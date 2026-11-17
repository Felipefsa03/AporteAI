<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\WhatsApp\HumanHandoff;
use App\Domain\WhatsApp\WebhookAuthenticator;
use App\Domain\WhatsApp\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $token = config('whatsapp.webhook.verify_token');
        $valid = is_string($token)
            && $token !== ''
            && $request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === $token;

        return $valid
            ? response()->json(['challenge' => $request->query('hub_challenge')])
            : response()->json(['message' => 'invalid verification'], 403);
    }

    public function webhook(
        Request $request,
        WebhookAuthenticator $authenticator,
        WebhookProcessor $processor,
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

        $messages = $payload['entry'][0]['changes'][0]['value']['messages'] ?? [];
        if (! is_array($messages)) {
            $messages = [];
        }

        $statuses = [];
        foreach ($messages as $message) {
            $externalId = isset($message['id']) ? (string) $message['id'] : '';
            if ($externalId === '') {
                continue;
            }
            $statuses[$externalId] = $processor->process($externalId, $payload, now()->toDateTimeImmutable());
        }

        return response()->json([
            'processed' => count($statuses),
            'statuses' => $statuses,
            'handoff' => in_array('human_handoff', $statuses, true) ? $handoff->instructions() : null,
        ]);
    }
}
