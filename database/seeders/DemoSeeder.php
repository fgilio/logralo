<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReactionEmoji;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use App\Services\PhotoProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * A believable month of group activity, for local development and screenshots.
 * Never run in production — DatabaseSeeder only calls it outside production.
 */
final class DemoSeeder extends Seeder
{
    private const array MEMBERS = [
        ['Franco Gilio', 'franco@logralo.test', 'Europe/Lisbon'],
        ['Martín Rodríguez', 'martin@logralo.test', 'America/Montevideo'],
        ['Lucía Fernández', 'lucia@logralo.test', 'America/Montevideo'],
        ['Diego Pereyra', 'diego@logralo.test', 'America/Argentina/Buenos_Aires'],
        ['Sofía Méndez', 'sofia@logralo.test', 'America/Montevideo'],
    ];

    private const array GOALS = [
        ['🏋️', 'Gimnasio'],
        ['📚', 'Leer 20 páginas'],
        ['💧', 'Tomar 2L de agua'],
        ['🧘', 'Meditar'],
        ['🏃', 'Correr'],
        ['🥗', 'Comer bien'],
    ];

    public function run(): void
    {
        $photos = resolve(PhotoProcessor::class);

        $users = collect(self::MEMBERS)->map(fn (array $member): User => User::query()->create([
            'name' => $member[0],
            'email' => $member[1],
            'timezone' => $member[2],
            'password' => Hash::make('password'),
            'password_set_at' => CarbonImmutable::now(),
        ]));

        foreach ($users as $user) {
            $goals = collect(self::GOALS)
                ->shuffle()
                ->take(random_int(2, 4))
                ->values()
                ->map(fn (array $goal, int $position): Goal => $user->goals()->create([
                    'emoji' => $goal[0],
                    'name' => $goal[1],
                    'position' => $position + 1,
                ]));

            $clock = $user->clock();

            foreach ($goals as $goal) {
                for ($daysAgo = 24; $daysAgo >= 0; $daysAgo--) {
                    // A believable streak: mostly kept, occasionally dropped.
                    if (random_int(1, 100) > 74) {
                        continue;
                    }

                    $day = $clock->today()->subDays($daysAgo);
                    $withPhoto = random_int(1, 100) <= 62;

                    $stored = $withPhoto ? $photos->store($this->fakePhoto()) : null;

                    $mark = Mark::query()->create([
                        'goal_id' => $goal->id,
                        'user_id' => $user->id,
                        'marked_on' => $day->toDateString(),
                        'photo_key' => $stored?->key,
                        'photo_width' => $stored?->width,
                        'photo_height' => $stored?->height,
                        'note' => random_int(1, 100) <= 18 ? 'Costó, pero salió 💪' : null,
                        'created_at' => $day->setTime(random_int(7, 22), random_int(0, 59)),
                        'updated_at' => $day->setTime(random_int(7, 22), random_int(0, 59)),
                    ]);

                    foreach ($users->reject(fn (User $other): bool => $other->is($user))->random(random_int(0, 3)) as $reactor) {
                        Reaction::query()->create([
                            'mark_id' => $mark->id,
                            'user_id' => $reactor->id,
                            'emoji' => fake()->randomElement(ReactionEmoji::cases()),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * A stand-in camera roll: a coloured gradient at phone proportions, so the
     * real photo pipeline runs and the feed has something to show.
     */
    private function fakePhoto(): UploadedFile
    {
        $image = resolve(ImageManager::class)->createImage(1200, 1500)->fill(
            sprintf('#%02x%02x%02x', random_int(30, 220), random_int(30, 220), random_int(30, 220)),
        );

        $path = storage_path('app/'.Str::ulid().'.jpg');

        File::ensureDirectoryExists(storage_path('app'));
        File::put($path, (string) $image->encode(new JpegEncoder(quality: 70)));

        return new UploadedFile($path, 'demo.jpg', 'image/jpeg', test: true);
    }
}
