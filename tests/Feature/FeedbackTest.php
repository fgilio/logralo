<?php

declare(strict_types=1);

use App\Actions\SendFeedback;
use App\Mail\FeedbackReceived;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Sending
|--------------------------------------------------------------------------
*/

it('keeps what a member wrote, and where they wrote it from', function (): void {
    $user = User::factory()->create();

    $feedback = resolve(SendFeedback::class)->handle($user, 'La cámara no abre', 'perfil');

    expect($feedback->user_id)->toBe($user->id)
        ->and($feedback->body)->toBe('La cámara no abre')
        ->and($feedback->page)->toBe('perfil');
});

it('takes feedback with no page behind it', function (): void {
    $user = User::factory()->create();

    expect(resolve(SendFeedback::class)->handle($user, 'Ponele modo oscuro')->page)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The nudge
|--------------------------------------------------------------------------
*/

it('mails the inbox when there is one', function (): void {
    Mail::fake();
    config()->set('logralo.feedback.email', 'hola@logralo.test');

    $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

    resolve(SendFeedback::class)->handle($user, 'La cámara no abre');

    Mail::assertSent(FeedbackReceived::class, fn (FeedbackReceived $mail): bool => $mail->hasTo('hola@logralo.test')
        // Answering the mail answers the member, not the app's own address.
        && $mail->hasReplyTo('ana@example.com')
        && $mail->feedback->body === 'La cámara no abre');
});

it('mails nobody when no inbox is configured', function (): void {
    Mail::fake();
    config()->set('logralo.feedback.email', '');

    resolve(SendFeedback::class)->handle(User::factory()->create(), 'Ponele modo oscuro');

    Mail::assertNothingSent();

    expect(Feedback::query()->count())->toBe(1);
});

it('keeps the feedback when the mail cannot go out', function (): void {
    // The row is the deliverable. A provider having a bad afternoon reports
    // itself and is not allowed to swallow what somebody just typed.
    config()->set('logralo.feedback.email', 'hola@logralo.test');
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Resend caído'));

    $user = User::factory()->create();

    $feedback = resolve(SendFeedback::class)->handle($user, 'La cámara no abre');

    expect($feedback->exists)->toBeTrue()
        ->and(Feedback::query()->sole()->body)->toBe('La cámara no abre');
});

/*
|--------------------------------------------------------------------------
| The sheet
|--------------------------------------------------------------------------
*/

it('sends what the member typed, from the screen they were on', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    // Mounted the way the layout mounts it: with the path of the screen being
    // drawn. The property is locked, so this is the only way it is ever set.
    Livewire::actingAs($user)
        ->test('feedback', ['page' => 'perfil'])
        ->set('body', 'La cámara no abre')
        ->call('send')
        ->assertHasNoErrors()
        // Emptied, so the next tap on the button opens a blank sheet.
        ->assertSet('body', '');

    $feedback = Feedback::query()->sole();

    expect($feedback->user_id)->toBe($user->id)
        ->and($feedback->body)->toBe('La cámara no abre')
        ->and($feedback->page)->toBe('perfil');
});

it('refuses an empty message', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('feedback')
        ->set('body', '  ')
        ->call('send')
        ->assertHasErrors(['body' => 'required']);

    expect(Feedback::query()->count())->toBe(0);
});

it('refuses a message longer than the sheet allows', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('feedback')
        ->set('body', str_repeat('a', config()->integer('logralo.feedback.max_length') + 1))
        ->call('send')
        ->assertHasErrors(['body' => 'max']);

    expect(Feedback::query()->count())->toBe(0);
});

it('puts the button on every screen behind the gate', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('today'))->assertOk()->assertSee('open-feedback', escape: false);
    $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('open-feedback', escape: false);
});

it('leaves the login screen alone', function (): void {
    // The sheet needs a member to attribute the message to, and the login page
    // has none — it is drawn by `layouts::auth`, which carries no button.
    $this->get(route('login'))->assertOk()->assertDontSee('open-feedback', escape: false);
});
