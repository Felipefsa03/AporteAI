<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\WhatsApp\HumanHandoff;
use App\Domain\WhatsApp\WebhookAuthenticator;
use App\Domain\WhatsApp\WebhookDeduplicator;
use App\Domain\WhatsApp\WebhookEventParser;
use App\Domain\WhatsApp\WebhookProcessor;
use App\Domain\WhatsApp\WhatsAppReceiptService;
use App\Jobs\DispatchWhatsAppOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class WhatsAppWebhookController extends Controller
{
    /** Contrato Meta: o challenge é texto puro, sem JSON. */
    public function verify(Request $request): Response
    {
        $token = config('whatsapp.webhook.verify_token');
        $mode = $request->query('hub_mode');
        $verifyToken = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $valid = is_string($token)
            && $token !== ''
            && $mode === 'subscribe'
            && $verifyToken === $token
            && is_string($challenge);

        if (! $valid) {
            return response('invalid verification', 403, ['Content-Type' => 'text/plain']);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function webhook(
        Request $request,
        WebhookAuthenticator $authenticator,
        WebhookEventParser $parser,
        WebhookDeduplicator $deduplicator,
        WebhookProcessor $processor,
        WhatsAppReceiptService $receipts,
        HumanHandoff $handoff,
    ): JsonResponse {
        $raw = $request->getContent();

        if (! $authenticator->verify($raw, $request->header('x-hub-signature-256'))) {
            return response()->json(['message' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'invalid payload'], 422);
        }

        $receivedAt = now()->toDateTimeImmutable();
        $events = $parser->parse($payload, $receivedAt);

        $result = DB::transaction(function () use (
            $events,
            $receivedAt,
            $deduplicator,
            $processor,
            $receipts,
        ): array {
            $statuses = [];
            $replies = [];

            foreach ($events as $event) {
                $externalId = (string) ($event['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $inboxEventId = $deduplicator->claim($event, $receivedAt);
                if ($inboxEventId === null) {
                    $statuses[$externalId] = 'duplicate';

                    continue;
                }

                $event['inbox_event_id'] = $inboxEventId;

                if (($event['kind'] ?? '') === 'status') {
                    $statuses[$externalId] = $receipts->apply($event, $receivedAt);
                } else {
                    $ingest = $processor->ingest($event, $receivedAt, true);
                    $statuses[$externalId] = (string) ($ingest['status'] ?? 'ignored');

                    if (isset($ingest['reply']) && is_array($ingest['reply'])) {
                        $replies[$externalId] = $ingest['reply'];
                    }
                }

                $deduplicator->markProcessed((string) ($event['dedupe_key'] ?? ''), $receivedAt);
            }

            return ['statuses' => $statuses, 'replies' => $replies];
        });

        // A fila é despachada fora da transação; um crash antes do dispatch é
        // recuperado pelo comando/relay que varre a outbox pendente.
        DispatchWhatsAppOutbox::dispatch()->onQueue((string) config('whatsapp.queue'))->afterCommit();

        return response()->json([
            'processed' => count($result['statuses']),
            'statuses' => $result['statuses'],
            'replies' => $result['replies'],
            'handoff' => in_array('human_handoff', $result['statuses'], true)
                ? $handoff->instructions()
                : null,
        ]);
    }
}
