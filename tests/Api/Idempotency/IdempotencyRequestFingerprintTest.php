<?php

declare(strict_types=1);

namespace App\Tests\Api\Idempotency;

use App\Api\Idempotency\Service\IdempotencyRequestFingerprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class IdempotencyRequestFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), $this->tempFiles);
    }

    public function test_a_json_body_decides_the_hash(): void
    {
        $fingerprint = new IdempotencyRequestFingerprint();

        self::assertSame($fingerprint->of($this->json('{"a":1}')), $fingerprint->of($this->json('{"a":1}')));
        self::assertNotSame($fingerprint->of($this->json('{"a":1}')), $fingerprint->of($this->json('{"a":2}')));
    }

    public function test_the_files_of_a_multipart_request_decide_the_hash(): void
    {
        $fingerprint = new IdempotencyRequestFingerprint();

        $same = $fingerprint->of($this->multipart(['name' => 'X'], 'one'));
        self::assertSame($same, $fingerprint->of($this->multipart(['name' => 'X'], 'one')));
        self::assertNotSame($same, $fingerprint->of($this->multipart(['name' => 'X'], 'two')));
        self::assertNotSame($same, $fingerprint->of($this->multipart(['name' => 'Y'], 'one')));
    }

    public function test_a_failed_upload_has_a_hash_that_its_error_decides(): void
    {
        $fingerprint = new IdempotencyRequestFingerprint();

        $same = $fingerprint->of($this->failedUpload(\UPLOAD_ERR_INI_SIZE));
        self::assertSame($same, $fingerprint->of($this->failedUpload(\UPLOAD_ERR_INI_SIZE)));
        self::assertNotSame($same, $fingerprint->of($this->failedUpload(\UPLOAD_ERR_PARTIAL)));
    }

    private function failedUpload(int $error): Request
    {
        return Request::create('/api/v1/projects/x/artifacts', Request::METHOD_POST, ['name' => 'X'], files: ['files' => [new UploadedFile('', 'a.html', 'text/html', $error, true)]]);
    }

    private function json(string $body): Request
    {
        return Request::create('/api/v1/projects', Request::METHOD_POST, content: $body);
    }

    /** @param array<string, string> $fields */
    private function multipart(array $fields, string $contents): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'fingerprint');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);

        return Request::create('/api/v1/projects/x/artifacts', Request::METHOD_POST, $fields, files: ['files' => [new UploadedFile($path, 'a.html', 'text/html', null, true)]]);
    }
}
