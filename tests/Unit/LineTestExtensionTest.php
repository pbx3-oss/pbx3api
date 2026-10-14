<?php

use App\Support\LineTestExtension;

test('line test description marker', function () {
    expect(LineTestExtension::descriptionIsMarker('system:line-test'))->toBeTrue()
        ->and(LineTestExtension::descriptionIsMarker(' system:line-test '))->toBeTrue()
        ->and(LineTestExtension::descriptionIsMarker('Line quality test'))->toBeFalse()
        ->and(LineTestExtension::descriptionIsMarker(null))->toBeFalse();
});

test('line test preferred dialable avoids emergency 999', function () {
    expect(LineTestExtension::preferredPkey(3))->toBe('981')
        ->and(LineTestExtension::preferredPkey(4))->toBe('9801')
        ->and(LineTestExtension::preferredPkey(5))->toBe('98001')
        // ExtLenPolicy::MIN=3 — sub-min lengths normalize to default (3→981)
        ->and(LineTestExtension::preferredPkey(2))->toBe('981');
});
