<?php

uses(Tests\TestCase::class);

use App\Services\Provision\ProvisionPreviewService;

beforeEach(function () {
    $this->streams = sys_get_temp_dir().'/pbx3-preview-streams-'.uniqid('', true);
    mkdir($this->streams);
    file_put_contents($this->streams.'/snomXml.Common', "<filter_registrar perm=\"$\">on</filter_registrar>\n");
    file_put_contents(
        $this->streams.'/snomXml.Extension',
        "<?xml version=\"1.0\"?>\n<settings>\n<phone-settings e=\"2\">\n#INCLUDE snomXml.Common\n"
        ."<user_name idx=\"1\" perm=\"$\">\$sipuser</user_name>\n"
        ."<user_pass idx=\"1\" perm=\"$\">\$password</user_pass>\n"
        ."<user_idle_text idx=\"1\" perm=\"$\">\$ext(\$desc)</user_idle_text>\n"
        ."</phone-settings>\n</settings>\n"
    );
    putenv('PBX3_PROVISION_STREAMS='.$this->streams);
    $_ENV['PBX3_PROVISION_STREAMS'] = $this->streams;

    $kernel = dirname(__DIR__, 2).'/../pbx3/pbx3-1/opt/pbx3/php/classes/ProvisionKernel.php';
    putenv('PBX3_PROVISION_KERNEL='.$kernel);
    $_ENV['PBX3_PROVISION_KERNEL'] = $kernel;
});

afterEach(function () {
    foreach (['snomXml.Common', 'snomXml.Extension'] as $f) {
        @unlink($this->streams.'/'.$f);
    }
    @rmdir($this->streams);
    putenv('PBX3_PROVISION_STREAMS');
    unset($_ENV['PBX3_PROVISION_STREAMS']);
    putenv('PBX3_PROVISION_KERNEL');
    unset($_ENV['PBX3_PROVISION_KERNEL']);
});

test('preview expands and obfuscates user_pass', function () {
    $svc = new ProvisionPreviewService;
    $phone = (object) [
        'pkey' => '401',
        'shortuid' => '3cg94b',
        'passwd' => 'SecretPass99',
        'cluster' => 'hf3zzv',
        'macaddr' => '000413be48c0',
        'desc' => 'ael1',
        'provision' => "#INCLUDE snomXml.Extension\n",
    ];
    $r = $svc->preview($phone, null, ['domain' => 'pbx3.com', 'fqdn' => '08jzwn.pbx3.com']);
    expect($r['ok'])->toBeTrue();
    expect($r['body'])->toContain('<settings>');
    expect($r['body'])->toContain('>3cg94b</user_name>');
    expect($r['body'])->toContain('<user_pass');
    expect($r['body'])->toContain('********');
    expect($r['body'])->not->toContain('SecretPass99');
    expect($r['body'])->toContain('filter_registrar');
    expect($r['body'])->toContain('>401(ael1)</user_idle_text>');
});

test('preview reads Eloquent-style getAttributes (not array cast)', function () {
    $svc = new ProvisionPreviewService;
    $phone = new class
    {
        public function getAttributes(): array
        {
            return [
                'pkey' => '401',
                'shortuid' => '3cg94b',
                'passwd' => 'SecretPass99',
                'cluster' => 'hf3zzv',
                'desc' => 'ael1',
                'provision' => "#INCLUDE snomXml.Extension\n",
            ];
        }
    };
    $r = $svc->preview($phone, null, []);
    expect($r['ok'])->toBeTrue();
    expect($r['body'])->toContain('>3cg94b</user_name>');
    expect($r['body'])->toContain('>401(ael1)</user_idle_text>');
    expect($r['body'])->toContain('********');
    expect($r['body'])->not->toContain('SecretPass99');
});

test('preview reports empty provision', function () {
    $svc = new ProvisionPreviewService;
    $r = $svc->preview((object) ['pkey' => '1', 'provision' => ''], null, []);
    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('empty_provision');
});

test('preview accepts draft provision override', function () {
    $svc = new ProvisionPreviewService;
    $phone = (object) [
        'pkey' => '401',
        'shortuid' => 'abc123',
        'passwd' => 'x',
        'cluster' => 't1',
        'provision' => 'stale',
    ];
    $r = $svc->preview($phone, "#INCLUDE snomXml.Extension\n", []);
    expect($r['ok'])->toBeTrue();
    expect($r['body'])->toContain('>abc123</user_name>');
});
