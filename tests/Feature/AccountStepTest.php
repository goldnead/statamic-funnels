<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Der Konto-Schritt nach dem Kauf.
 *
 * Bisher entstand der Benutzer still im Hintergrund, aus der Adresse gebaut,
 * und niemand sagte ihm, dass es ihn gibt. Jetzt fragt eine Seite nach Name
 * und Passwort. Die Adresse ist die des Besuchs — das ist die Sicherheit
 * dieses Schritts: wer ein Konto will, muss den Weg gegangen sein, der zu
 * dieser Adresse gehoert.
 */
class AccountStepTest extends TestCase
{
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * @param  array<string, mixed>  $accountConfig
     */
    protected function funnel(array $accountConfig = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'account_1', 'type' => 'account', 'label' => 'Konto', 'slug' => 'konto', 'config' => $accountConfig],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'account_1', 'from_output' => 'default'],
            ['from_node_key' => 'account_1', 'to_node_key' => 'finish_1', 'from_output' => 'default'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Den Weg bis zum Konto-Schritt gehen, mit Adresse. */
    protected function arrive(string $email = 'maria@example.com'): void
    {
        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => $email, 'name' => 'Maria Beispiel']);
        $this->asVisitor()->get('/f/kurs/konto')->assertOk();
    }

    #[Test]
    public function the_page_shows_the_visits_address_read_only_and_asks_for_name_and_password(): void
    {
        $this->funnel();
        $this->arrive();

        $html = $this->asVisitor()->get('/f/kurs/konto')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/type="email" value="maria@example.com" readonly/', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
        $this->assertStringContainsString('name="skip"', $html);
    }

    #[Test]
    public function it_creates_the_user_logs_them_in_and_carries_on(): void
    {
        $this->funnel();
        $this->arrive();

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria Beispiel',
            'password' => 'geheim-und-lang',
            'password_confirmation' => 'geheim-und-lang',
        ])->assertSessionHasNoErrors()->assertRedirect('/f/kurs/danke');

        $user = User::findByEmail('maria@example.com');

        $this->assertNotNull($user);
        $this->assertSame('Maria Beispiel', $user->get('name'));
        $this->assertTrue(Hash::check('geheim-und-lang', (string) $user->password()));
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id(), Auth::user()?->id());

        $this->assertSame(1, FunnelVisit::query()->sole()->events()
            ->where('node_key', 'account_1')->where('event', FunnelStepEvent::SUBMITTED)->count());
    }

    #[Test]
    public function an_existing_user_with_that_address_is_updated_not_duplicated(): void
    {
        $this->funnel();
        tap(User::make()->email('maria@example.com')->password('altes-passwort-1'))->save();

        $this->arrive();

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria B.',
            'password' => 'neues-passwort-2',
            'password_confirmation' => 'neues-passwort-2',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, User::all()->filter(fn ($u) => $u->email() === 'maria@example.com')->count());

        $user = User::findByEmail('maria@example.com');
        $this->assertSame('Maria B.', $user->get('name'));
        $this->assertTrue(Hash::check('neues-passwort-2', (string) $user->password()));
    }

    #[Test]
    public function the_password_has_rules(): void
    {
        $this->funnel();
        $this->arrive();

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria', 'password' => 'kurz', 'password_confirmation' => 'kurz',
        ])->assertSessionHasErrors(['password']);

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria', 'password' => 'lang-genug-aber', 'password_confirmation' => 'anders-als-oben',
        ])->assertSessionHasErrors(['password']);

        $this->assertNull(User::findByEmail('maria@example.com'));
    }

    #[Test]
    public function later_carries_on_without_an_account(): void
    {
        $this->funnel();
        $this->arrive();

        $this->asVisitor()->post('/f/kurs/account_1/advance', ['skip' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/f/kurs/danke');

        $this->assertNull(User::findByEmail('maria@example.com'));
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function later_is_refused_when_the_step_requires_an_account(): void
    {
        $this->funnel(['optional' => 'no']);
        $this->arrive();

        $this->asVisitor()->get('/f/kurs/konto')->assertOk()->assertDontSee('name="skip"', false);

        $this->asVisitor()->post('/f/kurs/account_1/advance', ['skip' => '1'])
            ->assertSessionHasErrors(['account']);
    }

    #[Test]
    public function login_after_can_be_switched_off(): void
    {
        $this->funnel(['login_after' => 'no']);
        $this->arrive();

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria', 'password' => 'geheim-und-lang', 'password_confirmation' => 'geheim-und-lang',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(User::findByEmail('maria@example.com'));
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function another_browser_cannot_take_over_a_visit_it_never_walked(): void
    {
        $this->funnel();
        $this->arrive();

        // Ein anderer Cookie: ein anderer Besuch, der den Konto-Schritt nie
        // erreicht hat. 403, wie bei jedem Schritt, den niemand betreten hat —
        // und der Besuch der ersten Person bleibt, wie er war.
        $this->asVisitor(str_repeat('z', 32))->post('/f/kurs/account_1/advance', [
            'name' => 'Eindringling', 'password' => 'geheim-und-lang', 'password_confirmation' => 'geheim-und-lang',
        ])->assertForbidden();

        $this->assertNull(User::findByEmail('maria@example.com'));
    }

    #[Test]
    public function without_an_address_on_the_visit_there_is_no_account(): void
    {
        $funnel = $this->funnel();
        // Direkt zum Konto-Schritt, ohne das Formular: es gibt ihn als Seite,
        // aber keine Adresse, zu der ein Konto gehoeren koennte.
        $funnel->edges()->where('to_node_key', 'account_1')->delete();
        $this->asVisitor()->get('/f/kurs/konto')->assertOk();

        $this->asVisitor()->post('/f/kurs/account_1/advance', [
            'name' => 'Maria', 'password' => 'geheim-und-lang', 'password_confirmation' => 'geheim-und-lang',
        ])->assertSessionHasErrors(['account']);

        $this->assertSame(0, User::all()->count());
    }
}
