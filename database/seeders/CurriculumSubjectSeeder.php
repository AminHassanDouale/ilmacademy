<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Curriculum;

class CurriculumSubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Make sure curricula exist first
        $curricula = Curriculum::all();

        if ($curricula->isEmpty()) {
            $this->command->error('No curricula found. Please run CurriculumSeeder first.');
            return;
        }

        // Get the first curriculum (you can modify this logic as needed)
        $curriculum = $curricula->first();

        // Elementary Level Subjects
        $elementarySubjects = [
            [
                'name' => 'Elementary Mathematics',
                'code' => 'MATH101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
            [
                'name' => 'Elementary Science',
                'code' => 'SCI101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
            [
                'name' => 'Elementary English',
                'code' => 'ENG101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
            [
                'name' => 'Elementary Social Studies',
                'code' => 'SS101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
            [
                'name' => 'Elementary Arts',
                'code' => 'ART101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
            [
                'name' => 'Elementary Physical Education',
                'code' => 'PE101',
                'curriculum_id' => $curriculum->id,
                'level' => 1,
            ],
        ];

        // Middle Level Subjects
        $middleSubjects = [
            [
                'name' => 'Intermediate Mathematics',
                'code' => 'MATH201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
            [
                'name' => 'Intermediate Science',
                'code' => 'SCI201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
            [
                'name' => 'Intermediate English',
                'code' => 'ENG201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
            [
                'name' => 'Intermediate Social Studies',
                'code' => 'SS201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
            [
                'name' => 'Computer Basics',
                'code' => 'CS201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
            [
                'name' => 'Foreign Language',
                'code' => 'FL201',
                'curriculum_id' => $curriculum->id,
                'level' => 2,
            ],
        ];

        // Advanced Level Subjects
        $advancedSubjects = [
            [
                'name' => 'Advanced Mathematics',
                'code' => 'MATH301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
            [
                'name' => 'Advanced Science',
                'code' => 'SCI301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
            [
                'name' => 'Advanced English',
                'code' => 'ENG301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
            [
                'name' => 'History',
                'code' => 'HIST301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
            [
                'name' => 'Geography',
                'code' => 'GEO301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
            [
                'name' => 'Computer Science',
                'code' => 'CS301',
                'curriculum_id' => $curriculum->id,
                'level' => 3,
            ],
        ];

        // If you have multiple curricula, you can add subjects for each
        if ($curricula->count() > 1) {
            $secondCurriculum = $curricula->skip(1)->first();

            $specializedSubjects = [
                [
                    'name' => 'Advanced Programming',
                    'code' => 'PROG401',
                    'curriculum_id' => $secondCurriculum->id,
                    'level' => 4,
                ],
                [
                    'name' => 'Data Structures',
                    'code' => 'DS401',
                    'curriculum_id' => $secondCurriculum->id,
                    'level' => 4,
                ],
                [
                    'name' => 'Database Management',
                    'code' => 'DB401',
                    'curriculum_id' => $secondCurriculum->id,
                    'level' => 4,
                ],
                [
                    'name' => 'Web Development',
                    'code' => 'WEB401',
                    'curriculum_id' => $secondCurriculum->id,
                    'level' => 4,
                ],
            ];

            // Merge specialized subjects with advanced subjects
            $advancedSubjects = array_merge($advancedSubjects, $specializedSubjects);
        }

        // Combine all subjects
        $allSubjects = array_merge($elementarySubjects, $middleSubjects, $advancedSubjects);

        // Create subjects
        foreach ($allSubjects as $subjectData) {
            Subject::updateOrCreate(
                [
                    'code' => $subjectData['code'],
                    'curriculum_id' => $subjectData['curriculum_id']
                ],
                $subjectData
            );
        }

        $this->command->info('Successfully seeded ' . count($allSubjects) . ' subjects.');
    }
}
