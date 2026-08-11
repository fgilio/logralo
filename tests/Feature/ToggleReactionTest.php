<?php

declare(strict_types=1);

use App\Actions\ToggleReaction;
use App\Enums\ReactionEmoji;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('adds a reaction on the first tap', function (): void {
    $mark = Mark::factory()->create();
    $user = User::factory()->create();

    $reaction = resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Fire);

    expect($reaction)->toBeInstanceOf(Reaction::class)
        ->and($reaction?->emoji)->toBe(ReactionEmoji::Fire)
        ->and($reaction?->user_id)->toBe($user->id)
        ->and($reaction?->mark_id)->toBe($mark->id)
        ->and($mark->reactions()->count())->toBe(1);
});

it('removes the reaction when the same emoji is tapped again', function (): void {
    $mark = Mark::factory()->create();
    $user = User::factory()->create();

    resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Muscle);
    $second = resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Muscle);

    expect($second)->toBeNull()
        ->and($mark->reactions()->count())->toBe(0)
        ->and(Reaction::query()->count())->toBe(0);
});

it('swaps the emoji in place instead of adding a second row', function (): void {
    $mark = Mark::factory()->create();
    $user = User::factory()->create();

    $first = resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Clap);
    $swapped = resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Laugh);

    expect($swapped?->id)->toBe($first?->id)
        ->and($swapped?->emoji)->toBe(ReactionEmoji::Laugh)
        ->and($mark->reactions()->count())->toBe(1)
        ->and($mark->reactions()->first()?->emoji)->toBe(ReactionEmoji::Laugh);
});

it('lets a member react again after removing a reaction', function (): void {
    $mark = Mark::factory()->create();
    $user = User::factory()->create();

    resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Point);
    resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Point);

    $again = resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Fire);

    expect($again?->emoji)->toBe(ReactionEmoji::Fire)
        ->and($mark->reactions()->count())->toBe(1);
});

it('keeps one row per member per mark at the database level', function (): void {
    $mark = Mark::factory()->create();
    $user = User::factory()->create();

    Reaction::factory()->for($mark)->for($user)->create(['emoji' => ReactionEmoji::Fire]);

    // Wrapped in its own transaction — a savepoint, since RefreshDatabase has
    // one open — because on Postgres a failed statement aborts the surrounding
    // transaction and every later query in this test with it.
    expect(fn (): bool => DB::transaction(fn (): bool => DB::table('reactions')->insert([
        'id' => (string) Str::ulid(),
        'mark_id' => $mark->id,
        'user_id' => $user->id,
        'emoji' => ReactionEmoji::Clap->value,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(Reaction::query()->count())->toBe(1);
});

it('lets two members react to the same mark', function (): void {
    $mark = Mark::factory()->create();
    $one = User::factory()->create();
    $two = User::factory()->create();

    resolve(ToggleReaction::class)->handle($mark, $one, ReactionEmoji::Fire);
    resolve(ToggleReaction::class)->handle($mark, $two, ReactionEmoji::Fire);

    expect($mark->reactions()->count())->toBe(2)
        ->and($mark->reactions()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$one->id, $two->id])->sort()->values()->all());
});

it('leaves the other member reaction alone when one is removed', function (): void {
    $mark = Mark::factory()->create();
    $one = User::factory()->create();
    $two = User::factory()->create();

    resolve(ToggleReaction::class)->handle($mark, $one, ReactionEmoji::Fire);
    resolve(ToggleReaction::class)->handle($mark, $two, ReactionEmoji::Clap);
    resolve(ToggleReaction::class)->handle($mark, $one, ReactionEmoji::Fire);

    expect($mark->reactions()->count())->toBe(1)
        ->and($mark->reactions()->first()?->user_id)->toBe($two->id);
});

it('keeps reactions on one mark out of another', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    $one = Mark::factory()->for($goal)->on('2026-08-10')->create();
    $two = Mark::factory()->for($goal)->on('2026-08-11')->create();

    resolve(ToggleReaction::class)->handle($one, $user, ReactionEmoji::Fire);
    resolve(ToggleReaction::class)->handle($two, $user, ReactionEmoji::Clap);

    expect($one->reactions()->count())->toBe(1)
        ->and($two->reactions()->count())->toBe(1)
        ->and($one->reactions()->first()?->emoji)->toBe(ReactionEmoji::Fire)
        ->and($two->reactions()->first()?->emoji)->toBe(ReactionEmoji::Clap);
});

it('lets a member react to their own mark', function (): void {
    $user = User::factory()->create();
    $mark = Mark::factory()->for(Goal::factory()->for($user))->create(['user_id' => $user->id]);

    resolve(ToggleReaction::class)->handle($mark, $user, ReactionEmoji::Laugh);

    expect($mark->reactions()->count())->toBe(1);
});

it('goes away with the mark it belongs to', function (): void {
    $mark = Mark::factory()->create();
    resolve(ToggleReaction::class)->handle($mark, User::factory()->create(), ReactionEmoji::Fire);

    $mark->delete();

    expect(Reaction::query()->count())->toBe(0);
});

it('toggles a reaction from the feed', function (): void {
    $viewer = User::factory()->create();
    Goal::factory()->for($viewer)->create();
    $mark = Mark::factory()->create();

    $component = Livewire::actingAs($viewer)->test('feed');

    $component->call('react', $mark->id, ReactionEmoji::Fire->value);

    expect($mark->reactions()->count())->toBe(1);

    $component->call('react', $mark->id, ReactionEmoji::Fire->value);
    expect($mark->reactions()->count())->toBe(0);
});
