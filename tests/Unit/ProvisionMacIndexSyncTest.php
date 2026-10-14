<?php

namespace Tests\Unit;

use App\Services\Fleet\GatekeeperCatalogClient;
use App\Services\Fleet\ProvisionMacIndexSync;
use PHPUnit\Framework\TestCase;

final class ProvisionMacIndexSyncTest extends TestCase
{
    public function test_claim_is_noop_when_gatekeeper_unconfigured(): void
    {
        $client = $this->createMock(GatekeeperCatalogClient::class);
        $client->method('isConfigured')->willReturn(false);
        $client->expects($this->never())->method('claimMac');

        $sync = new ProvisionMacIndexSync($client);
        $sync->claim('aabbccddeeff', 'dhbm8x');
        $this->assertTrue(true);
    }

    public function test_clear_is_noop_when_gatekeeper_unconfigured(): void
    {
        $client = $this->createMock(GatekeeperCatalogClient::class);
        $client->method('isConfigured')->willReturn(false);
        $client->expects($this->never())->method('clearMac');

        $sync = new ProvisionMacIndexSync($client);
        $sync->clear('aabbccddeeff');
        $this->assertTrue(true);
    }
}
