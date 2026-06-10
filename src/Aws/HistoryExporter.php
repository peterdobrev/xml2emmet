<?php
declare(strict_types=1);
namespace App\Aws;

use Aws\S3\S3Client;

final class HistoryExporter
{
    private S3Client $s3;

    public function __construct(private string $bucket, string $region)
    {
        $this->s3 = new S3Client(['version' => 'latest', 'region' => $region]);
    }

    /** @param list<array<string,mixed>> $rows */
    public function export(int $userId, array $rows): string
    {
        $key  = sprintf('exports/user-%d/%s.json', $userId, gmdate('Y-m-d\THis\Z'));
        $body = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => $body,
            'ContentType' => 'application/json',
        ]);

        $cmd = $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        return (string) $this->s3->createPresignedRequest($cmd, '+1 hour')->getUri();
    }
}
