<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The staff camera page embeds the station visualizer configured by an admin. */
class CameraPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function an_admin_sees_the_configured_camera(): void
    {
        settings()->setMany(['camera.preview_url' => 'http://192.168.40.192/']);

        $this->actingAs($this->admin())
            ->get('/camera')
            ->assertOk()
            ->assertViewHas('url', 'http://192.168.40.192')
            ->assertSee('http://192.168.40.192/stream-frame', false);
    }

    #[Test]
    public function a_teacher_sees_the_configured_camera(): void
    {
        settings()->setMany(['camera.preview_url' => 'http://192.168.40.192/']);

        $this->actingAs($this->teacher())
            ->get('/camera')
            ->assertOk()
            ->assertSee('http://192.168.40.192/stream-frame', false);
    }

    #[Test]
    public function an_admin_saves_the_camera_url_through_the_settings_desk(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings'))
            ->put(route('admin.settings.update'), [
                'settings' => ['camera.preview_url' => 'http://192.168.40.192'],
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame('http://192.168.40.192', settings()->cameraPreviewUrl());
    }

    #[Test]
    public function without_a_url_the_page_explains_how_to_configure_it(): void
    {
        settings()->setMany(['camera.preview_url' => '']);

        $this->actingAs($this->admin())
            ->get('/camera')
            ->assertOk()
            ->assertViewHas('url', null)
            ->assertSee('Falta la URL de la cámara', false);
    }

    #[Test]
    public function a_student_is_forbidden(): void
    {
        $student = User::query()->where('role', 'student')->firstOrFail();

        $this->actingAs($student)->get('/camera')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/camera')->assertRedirect('/login');
    }
}
