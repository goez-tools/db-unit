<?php

/** @var \Illuminate\Database\Eloquent\Factory $factory */

$factory->define(\Tests\fixture\User::class, function (\Faker\Generator $faker) {
    return [
        'name' => $faker->name,
        'email' => $faker->email,
    ];
});
