<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subjectsByProgram = [
            'PEP' => [
                ['name' => 'Basic Mathematics', 'code' => 'MATH-01', 'level' => '1'],
                ['name' => 'English Language', 'code' => 'ENG-01', 'level' => '1'],
                ['name' => 'Science', 'code' => 'SCI-01', 'level' => '1'],
                ['name' => 'History', 'code' => 'HIST-01', 'level' => '1'],
                ['name' => 'Art', 'code' => 'ART-01', 'level' => '1'],
                ['name' => 'Physical Education', 'code' => 'PE-01', 'level' => '1'],
            ],
            'MSP' => [
                ['name' => 'Intermediate Mathematics', 'code' => 'MATH-02', 'level' => '2'],
                ['name' => 'English Literature', 'code' => 'ENG-02', 'level' => '2'],
                ['name' => 'Biology', 'code' => 'BIO-01', 'level' => '2'],
                ['name' => 'Chemistry', 'code' => 'CHEM-01', 'level' => '2'],
                ['name' => 'Physics', 'code' => 'PHYS-01', 'level' => '2'],
                ['name' => 'World History', 'code' => 'HIST-02', 'level' => '2'],
            ],
            'HSP' => [
                ['name' => 'Advanced Mathematics', 'code' => 'MATH-03', 'level' => '3'],
                ['name' => 'Literature & Composition', 'code' => 'ENG-03', 'level' => '3'],
                ['name' => 'Advanced Biology', 'code' => 'BIO-02', 'level' => '3'],
                ['name' => 'Advanced Chemistry', 'code' => 'CHEM-02', 'level' => '3'],
                ['name' => 'Advanced Physics', 'code' => 'PHYS-02', 'level' => '3'],
                ['name' => 'Economics', 'code' => 'ECON-01', 'level' => '3'],
            ],
            'IB' => [
                ['name' => 'IB Mathematics', 'code' => 'IB-MATH', 'level' => '4'],
                ['name' => 'IB English', 'code' => 'IB-ENG', 'level' => '4'],
                ['name' => 'IB Biology', 'code' => 'IB-BIO', 'level' => '4'],
                ['name' => 'Theory of Knowledge', 'code' => 'IB-TOK', 'level' => '4'],
            ],
            'SST' => [
                ['name' => 'Advanced Calculus', 'code' => 'MATH-04', 'level' => '4'],
                ['name' => 'Organic Chemistry', 'code' => 'CHEM-03', 'level' => '4'],
                ['name' => 'Quantum Physics', 'code' => 'PHYS-03', 'level' => '4'],
                ['name' => 'Computer Science', 'code' => 'CS-01', 'level' => '4'],
            ],
        ];

        foreach ($subjectsByProgram as $programCode => $subjects) {
            $curriculum = Curriculum::where('code', $programCode)->first();

            if ($curriculum) {
                foreach ($subjects as $subject) {
                    Subject::create([
                        'curriculum_id' => $curriculum->id,
                        'name' => $subject['name'],
                        'code' => $subject['code'],
                        'level' => $subject['level'],
                    ]);
                }
            }
        }
    }
}
