<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/** SAFE: o limite de login precisa ser compartilhado por processos HTTP distintos. */
final class LoginRateLimitProcessesTest extends TestCase
{
    public function testRateLimitSurvivesSeparateHttpProcesses(): void
    {
        $email = 'cross-process-'.strtolower(Str::random(10)).'@example.test';
        $servers = [];

        try {
            [$firstPort, $secondPort] = $this->freePorts(2);
            $servers[] = $this->startServer($firstPort);
            $servers[] = $this->startServer($secondPort);

            // Cinco falhas distribuidas entre dois processos consomem o limite.
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                [$status, $body] = $this->postLogin($firstPort, $email);
                self::assertSame(401, $status, $body);
            }
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                [$status, $body] = $this->postLogin($secondPort, $email);
                self::assertSame(401, $status, $body);
            }

            [$status, $body] = $this->postLogin($firstPort, $email);
            self::assertSame(429, $status, $body);
            [$status, $body] = $this->postLogin($secondPort, $email);
            self::assertSame(429, $status, $body);
        } finally {
            foreach ($servers as $server) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    /** @return list<int> */
    private function freePorts(int $count): array
    {
        $ports = [];
        while (count($ports) < $count) {
            $port = random_int(21000, 49000);
            if (in_array($port, $ports, true) || ! $this->portIsFree($port)) {
                continue;
            }
            $ports[] = $port;
        }

        return $ports;
    }

    private function portIsFree(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket === false) {
            return true;
        }
        fclose($socket);

        return false;
    }

    /** @return resource */
    private function startServer(int $port)
    {
        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ];
        $process = proc_open($command, [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, base_path('public'));
        self::assertIsResource($process, 'Nao foi possivel iniciar o servidor HTTP de teste.');

        for ($wait = 0; $wait < 50; $wait++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $process;
            }
            usleep(100_000);
        }

        proc_terminate($process);
        proc_close($process);
        self::fail('O servidor HTTP de teste nao ficou pronto na porta '.$port.'.');
    }

    /** @return array{int, string} */
    private function postLogin(int $port, string $email): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode([
                    'email' => $email,
                    'password' => 'senha-errada',
                    'trace_id' => (string) Str::uuid(),
                ], JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $body = file_get_contents('http://127.0.0.1:'.$port.'/api/v1/admin/auth/login', false, $context);
        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        return [$status, (string) $body];
    }
}
