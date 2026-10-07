<?php

uses(Tests\TestCase::class);

use App\Services\Provision\ProvisionOuiMapper;

beforeEach(function () {
    $this->manuf = sys_get_temp_dir().'/pbx3-manuf-'.uniqid('', true).'.txt';
    file_put_contents($this->manuf, implode("\n", [
        '00:04:13 snom technology AG',
        '24:9A:D8 YEALINK(XIAMEN) NETWORK TECHNOLOGY CO.,LTD.',
        '48:25:67 Poly',
        '00:04:F2 Polycom',
        '0C:38:3E Fanvil Technology Co., Ltd',
        '00:80:F0 Panasonic Communications Co., Ltd.',
        '00:0B:82 Grandstream Networks, Inc.',
        '',
    ]));
    $this->mapper = new ProvisionOuiMapper($this->manuf);
});

afterEach(function () {
    if (isset($this->manuf) && is_file($this->manuf)) {
        @unlink($this->manuf);
    }
});

test('vendorFromMac maps Poly OUI 482567', function () {
    expect($this->mapper->vendorFromMac('482567B0A593'))->toBe('Poly');
});

test('vendorFromMac maps snom and yealink OUIs', function () {
    expect($this->mapper->vendorFromMac('000413AABBCC'))->toBe('snom technology AG');
    expect($this->mapper->vendorFromMac('249AD8AABBCC'))->toContain('YEALINK');
});

test('defaultProvisionForVendor yields INCLUDE grains', function () {
    expect($this->mapper->defaultProvisionForVendor('Poly'))
        ->toBe("#INCLUDE poly.Extension\n#INCLUDE poly.udp\n");
    expect($this->mapper->defaultProvisionForVendor('Polycom'))
        ->toBe("#INCLUDE poly.Extension\n#INCLUDE poly.udp\n");
    expect($this->mapper->defaultProvisionForVendor('snom technology AG'))
        ->toBe("#INCLUDE snom.Extension\n#INCLUDE snom.udp\n");
    expect($this->mapper->defaultProvisionForVendor('YEALINK(XIAMEN) NETWORK TECHNOLOGY CO.,LTD.'))
        ->toBe("#INCLUDE yealink.Extension\n#INCLUDE yealink.udp\n");
    expect($this->mapper->defaultProvisionForVendor('Fanvil Technology Co., Ltd'))
        ->toBe("#INCLUDE fanvil.Extension\n#INCLUDE fanvil.udp\n");
    expect($this->mapper->defaultProvisionForVendor('Panasonic Communications Co., Ltd.'))
        ->toBe("#INCLUDE Panasonic\n#INCLUDE panasonic.udp\n");
});

test('Grandstream and unknown vendors do not auto-fill', function () {
    expect($this->mapper->defaultProvisionForVendor('Grandstream Networks, Inc.'))->toBeNull();
    expect($this->mapper->defaultProvisionForVendor('Acme Widgets'))->toBeNull();
    expect($this->mapper->softFillPatch('000B82AABBCC', null, null))->toBe([]);
});

test('softFillPatch Poly sets INCLUDE and Always from empty/Once', function () {
    $patch = $this->mapper->softFillPatch('482567B0A593', null, 'Once');
    expect($patch['provision'])->toContain('poly.Extension');
    expect($patch['sndcreds'])->toBe('Always');

    $fromEmpty = $this->mapper->softFillPatch('482567B0A593', '', '');
    expect($fromEmpty['sndcreds'])->toBe('Always');
});

test('softFillPatch preserves non-empty provision', function () {
    $custom = "#INCLUDE snom.Extension\n";
    expect($this->mapper->softFillPatch('482567B0A593', $custom, 'Once'))->toBe([]);
});

test('softFillPatch leaves explicit sndcreds No alone for Poly', function () {
    $patch = $this->mapper->softFillPatch('482567B0A593', null, 'No');
    expect($patch)->toHaveKey('provision');
    expect($patch)->not->toHaveKey('sndcreds');
});

test('softFillPatch snom fills provision without sndcreds bump', function () {
    $patch = $this->mapper->softFillPatch('000413AABBCC', null, 'Once');
    expect($patch['provision'])->toContain('snom.Extension');
    expect($patch)->not->toHaveKey('sndcreds');
});
