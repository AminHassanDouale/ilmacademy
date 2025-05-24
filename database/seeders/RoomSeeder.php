<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rooms = [
            [
                'name' => 'Room 101',
                'capacity' => 30,
                'location' => 'Main Building, First Floor',
                'description' => 'Standard classroom',
                'has_projector' => true,
                'has_computers' => false,
                'is_accessible' => true,
                'floor' => 1,
                'building' => 'Main Building',
            ],
            [
                'name' => 'Science Lab',
                'capacity' => 24,
                'location' => 'Science Wing, Second Floor',
                'description' => 'Fully equipped science laboratory',
                'has_projector' => true,
                'has_computers' => true,
                'is_accessible' => true,
                'floor' => 2,
                'building' => 'Science Wing',
            ],
            [
                'name' => 'Computer Lab',
                'capacity' => 20,
                'location' => 'Technology Building, First Floor',
                'description' => 'Computer laboratory with 20 workstations',
                'has_projector' => true,
                'has_computers' => true,
                'is_accessible' => true,
                'floor' => 1,
                'building' => 'Technology Building',
            ],
            [
                'name' => 'Room 205',
                'capacity' => 25,
                'location' => 'Main Building, Second Floor',
                'description' => 'Standard classroom',
                'has_projector' => true,
                'has_computers' => false,
                'is_accessible' => false,
                'floor' => 2,
                'building' => 'Main Building',
            ],
            [
                'name' => 'Auditorium',
                'capacity' => 200,
                'location' => 'Main Building, Ground Floor',
                'description' => 'Large auditorium for assemblies and presentations',
                'has_projector' => true,
                'has_computers' => true,
                'is_accessible' => true,
                'floor' => 0,
                'building' => 'Main Building',
            ],
        ];

        foreach ($rooms as $room) {
            Room::create($room);
        }
    }
}
