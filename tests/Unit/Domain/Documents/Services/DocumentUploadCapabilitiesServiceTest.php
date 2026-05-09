<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\Services;

use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\DTO\MarkitdownHealthResult;
use App\Domain\Documents\Services\DocumentUploadCapabilitiesService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DocumentUploadCapabilitiesServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('rag:markitdown:health');
    }

    public function test_base_extension_does_not_trigger_markitdown_health_check(): void
    {
        $client = $this->createMock(MarkitdownClientInterface::class);
        $client->expects(self::never())->method('health');

        $service = new DocumentUploadCapabilitiesService($client);

        self::assertTrue($service->isExtensionAllowed('md'));
        self::assertTrue($service->isExtensionAllowed('docx'));
    }

    public function test_extended_extension_triggers_health_check(): void
    {
        $client = $this->createMock(MarkitdownClientInterface::class);
        $client->expects(self::once())
            ->method('health')
            ->willReturn(new MarkitdownHealthResult(
                isAvailable: false,
                status: 'down',
            ));

        $service = new DocumentUploadCapabilitiesService($client);

        self::assertFalse($service->isExtensionAllowed('pdf'));
    }
}
