<?php

namespace Tests\Feature\Web;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The settings desk writes through SettingsService, so a change is live
 * for the very next consumer: a new plastic rate is used by the next
 * classify, and an invalid retention value leaves the old one intact.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_page_passes_every_effective_value(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertViewHas('values', fn (array $values) => $values['points.plastic'] === 10
                && $values['photos.retention_days'] === 30
                && count($values) === 10);
    }

    #[Test]
    public function raising_the_plastic_rate_changes_the_next_classification(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings'))
            ->put(route('admin.settings.update'), ['settings' => ['points.plastic' => 25]])
            ->assertRedirect(route('admin.settings'));

        $event = $this->recyclingTapFor('Maria González');
        $this->fakeClassifier(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true]);

        $this->postSigned($event->id)->assertOk()->assertJsonPath('points_awarded', 25);
    }

    #[Test]
    public function an_invalid_retention_is_rejected_and_leaves_the_value_untouched(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings'))
            ->put(route('admin.settings.update'), ['settings' => ['photos.retention_days' => 0]])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors();

        $this->assertSame(30, settings()->photoRetentionDays());
    }

    #[Test]
    public function a_teacher_cannot_read_or_write_the_settings(): void
    {
        $this->actingAs($this->teacher())->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->teacher())
            ->put(route('admin.settings.update'), ['settings' => ['points.plastic' => 25]])
            ->assertForbidden();

        $this->assertSame(10, settings()->pointsFor(MaterialClass::Plastic));
    }

    /** @param  array<string, mixed>  $result */
    private function fakeClassifier(array $result): void
    {
        $this->swap(MaterialClassifier::class, new class($result) implements MaterialClassifier
        {
            /** @param  array<string, mixed>  $result */
            public function __construct(private array $result) {}

            public function classify(string $imagePath): array
            {
                return $this->result;
            }
        });
    }

    private function postSigned(int $eventId, ?UploadedFile $file = null): TestResponse
    {
        $file ??= UploadedFile::fake()->image('bottle.jpg');
        $hash = (string) hash_file('sha256', $file->getPathname());
        $canonical = DeviceRequestSigner::multipartCanonical((string) $eventId, $hash);
        $nonce = Str::random(32);

        return $this->call(
            'POST',
            '/api/v1/recycling/classify',
            ['event_id' => $eventId],
            [],
            ['image' => $file],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($this->reader(), 'POST', '/api/v1/recycling/classify', $canonical, $nonce),
            ]
        );
    }
}
