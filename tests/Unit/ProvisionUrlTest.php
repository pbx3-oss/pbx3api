<?php

use App\Support\ProvisionUrl;

test('normalize mac strips separators', function () {
    expect(ProvisionUrl::normalizeMac('AA:BB:CC:DD:EE:FF'))->toBe('aabbccddeeff')
        ->and(ProvisionUrl::normalizeMac('aabbccddeeff'))->toBe('aabbccddeeff')
        ->and(ProvisionUrl::normalizeMac('bad'))->toBeNull()
        ->and(ProvisionUrl::normalizeMac(null))->toBeNull();
});

test('home provision url shape', function () {
    expect(ProvisionUrl::forHome('08jzwn.pbx3.com', 'aa:bb:cc:dd:ee:ff'))
        ->toBe('https://08jzwn.pbx3.com:41363/provisioning/aabbccddeeff.cfg')
        ->and(ProvisionUrl::forHome('', 'aabbccddeeff'))->toBeNull()
        ->and(ProvisionUrl::forHome('x.pbx3.com', null))->toBeNull();
});
