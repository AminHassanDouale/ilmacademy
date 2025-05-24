<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use Illuminate\Database\Seeder;

class CurriculumSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $curricula = [
            [
                'name' => 'Primary Education Program',
                'code' => 'PEP',
                'description' => 'Basic education for primary school students (ages 6-11)',
            ],
            [
                'name' => 'Middle School Program',
                'code' => 'MSP',
                'description' => 'Comprehensive education for middle school students (ages 12-14)',
            ],
            [
                'name' => 'High School Program',
                'code' => 'HSP',
                'description' => 'Advanced education for high school students (ages 15-18)',
            ],
            [
                'name' => 'International Baccalaureate',
                'code' => 'IB',
                'description' => 'International education program for students aged 16-19',
            ],
            [
                'name' => 'Specialized Science Track',
                'code' => 'SST',
                'description' => 'Focused curriculum for students with interest in sciences and mathematics',
            ],
        ];

        foreach ($curricula as $curriculum) {
            Curriculum::create($curriculum);
        }
    }
}
