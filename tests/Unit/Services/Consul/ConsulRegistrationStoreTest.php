<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Consul;

use App\Services\Consul\ConsulRegistrationStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConsulRegistrationStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/consul-service-id-'.uniqid('', true).'.json';
        config([
            'consul.service_id_file' => $this->path,
            'filesystems.default' => 's3',
        ]);
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_persists_the_service_id_on_the_local_path_not_the_default_disk(): void
    {
        Carbon::setTestNow('2026-09-10T14:00:00+00:00');

        $store = new ConsulRegistrationStore;
        $store->save('portal-id-foreigner-abc12xyz', 'portal-id-foreigner');

        $this->assertTrue(File::isFile($this->path));
        Storage::disk('s3')->assertMissing('consul-service-id.json');

        $this->assertSame([
            'id' => 'portal-id-foreigner-abc12xyz',
            'name' => 'portal-id-foreigner',
            'registered_at' => '2026-09-10T14:00:00+00:00',
        ], $store->get());
        $this->assertSame('portal-id-foreigner-abc12xyz', $store->getServiceId());
    }

    #[Test]
    public function it_returns_null_when_the_file_is_missing_or_invalid(): void
    {
        $store = new ConsulRegistrationStore;

        $this->assertNull($store->get());
        $this->assertNull($store->getServiceId());

        File::put($this->path, '{not-json');
        $this->assertNull($store->get());

        File::put($this->path, json_encode(['name' => 'portal-id-foreigner'], JSON_THROW_ON_ERROR));
        $this->assertNull($store->get());
    }

    #[Test]
    public function it_forgets_the_local_file(): void
    {
        $store = new ConsulRegistrationStore;
        $store->save('portal-id-foreigner-abc12xyz', 'portal-id-foreigner');
        $store->forget();

        $this->assertFalse(File::isFile($this->path));
        $this->assertNull($store->getServiceId());
    }
}
