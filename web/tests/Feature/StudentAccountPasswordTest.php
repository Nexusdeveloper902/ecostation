<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\StudentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cada estudiante recibe una contraseña inicial PROPIA. La contraseña
 * compartida de la demo ya no es el valor por defecto; el override de
 * configuración existe solo para pruebas y despliegues controlados.
 */
class StudentAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $name): Student
    {
        return Student::create(['name' => $name]);
    }

    #[Test]
    public function two_provisioned_students_never_share_the_generated_password(): void
    {
        config(['ecostation.student_initial_password' => null]);

        $accounts = app(StudentAccountService::class);

        $first = $accounts->provisionFor($this->student('Primera Estudiante'));
        $second = $accounts->provisionFor($this->student('Segunda Estudiante'));

        $this->assertFalse($first['already']);
        $this->assertFalse($second['already']);
        $this->assertNotSame($first['password'], $second['password']);
        $this->assertGreaterThanOrEqual(12, strlen((string) $first['password']));
        $this->assertGreaterThanOrEqual(12, strlen((string) $second['password']));

        // The returned password is the real credential, not a placeholder.
        $this->assertTrue(Hash::check((string) $first['password'], $first['user']->fresh()->password));
        $this->assertTrue(Hash::check((string) $second['password'], $second['user']->fresh()->password));
    }

    #[Test]
    public function an_explicit_config_value_still_works_for_tests(): void
    {
        config(['ecostation.student_initial_password' => 'clave-de-prueba']);

        $result = app(StudentAccountService::class)->provisionFor($this->student('Tercera Estudiante'));

        $this->assertSame('clave-de-prueba', $result['password']);
        $this->assertTrue(Hash::check('clave-de-prueba', $result['user']->fresh()->password));
    }

    #[Test]
    public function an_existing_account_is_never_reissued_a_password(): void
    {
        $student = $this->student('Cuarta Estudiante');

        $first = app(StudentAccountService::class)->provisionFor($student);
        $again = app(StudentAccountService::class)->provisionFor($student);

        $this->assertTrue($again['already']);
        $this->assertNull($again['password']);
        $this->assertSame($first['user']->id, $again['user']->id);
    }

    #[Test]
    public function the_admin_creation_flashes_the_generated_password_once(): void
    {
        $this->seedDemo();
        config(['ecostation.student_initial_password' => null]);

        $classId = (int) Student::where('name', 'Maria González')->firstOrFail()->class_id;

        $response = $this->actingAs($this->admin())
            ->post('/admin/students', ['name' => 'Quinta Estudiante', 'class_id' => $classId])
            ->assertRedirect(route('admin.students'));

        $password = (string) $response->getSession()->get('account_password');
        $this->assertNotSame('', $password);
        $this->assertNotSame('password', $password);

        $account = Student::where('name', 'Quinta Estudiante')->firstOrFail()->account;
        $this->assertNotNull($account);
        $this->assertTrue(Hash::check($password, $account->password));
    }
}
