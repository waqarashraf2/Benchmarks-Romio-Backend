<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ChecklistTemplateSeeder extends Seeder
{
    /**
     * Pre-defined checklist items matching the old system.
     * These are the quality control items that were tracked in the legacy dashboard.
     */
    protected array $floorPlanChecklist = [
        // Drawer checklist items
        [
            'layer' => 'drawer',
            'title' => 'Missing Elements',
            'description' => 'Check for any missing elements in the floor plan that should be present',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Dimension',
            'description' => 'Verify all dimensions are correctly noted and accurate',
            'is_required' => true,
            'sort_order' => 2,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Structure',
            'description' => 'Check the structural elements are correctly represented',
            'is_required' => true,
            'sort_order' => 3,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Wrong Labeling',
            'description' => 'Verify all labels are correct and properly placed',
            'is_required' => true,
            'sort_order' => 4,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Wrong Area/Scale Issue',
            'description' => 'Check for any area calculation or scale inconsistencies',
            'is_required' => true,
            'sort_order' => 5,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Overlap Labeling',
            'description' => 'Ensure no labels are overlapping or obstructing other elements',
            'is_required' => false,
            'sort_order' => 6,
        ],
        [
            'layer' => 'drawer',
            'title' => 'Portal Instruction',
            'description' => 'Follow portal-specific instructions and guidelines',
            'is_required' => false,
            'sort_order' => 7,
        ],
        
        // Checker checklist items
        [
            'layer' => 'checker',
            'title' => 'Missing Elements',
            'description' => 'Verify no elements are missing from the floor plan',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'layer' => 'checker',
            'title' => 'Dimension Accuracy',
            'description' => 'Cross-check all dimensions against source documents',
            'is_required' => true,
            'sort_order' => 2,
        ],
        [
            'layer' => 'checker',
            'title' => 'Structure Verification',
            'description' => 'Verify structural elements match the source',
            'is_required' => true,
            'sort_order' => 3,
        ],
        [
            'layer' => 'checker',
            'title' => 'Label Accuracy',
            'description' => 'Verify all labels are correct and properly placed',
            'is_required' => true,
            'sort_order' => 4,
        ],
        [
            'layer' => 'checker',
            'title' => 'Area Calculation',
            'description' => 'Verify area calculations are accurate',
            'is_required' => true,
            'sort_order' => 5,
        ],
        [
            'layer' => 'checker',
            'title' => 'Scale Consistency',
            'description' => 'Ensure scale is consistent throughout the drawing',
            'is_required' => true,
            'sort_order' => 6,
        ],
        [
            'layer' => 'checker',
            'title' => 'Portal Guidelines',
            'description' => 'Ensure all portal-specific guidelines are followed',
            'is_required' => false,
            'sort_order' => 7,
        ],
        
        // QA checklist items
        [
            'layer' => 'qa',
            'title' => 'Final Quality Check',
            'description' => 'Comprehensive quality check before delivery',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'layer' => 'qa',
            'title' => 'Missing Elements Review',
            'description' => 'Final review for any missing elements',
            'is_required' => true,
            'sort_order' => 2,
        ],
        [
            'layer' => 'qa',
            'title' => 'Dimension Final Check',
            'description' => 'Final dimension accuracy verification',
            'is_required' => true,
            'sort_order' => 3,
        ],
        [
            'layer' => 'qa',
            'title' => 'Structure Final Check',
            'description' => 'Final structural accuracy verification',
            'is_required' => true,
            'sort_order' => 4,
        ],
        [
            'layer' => 'qa',
            'title' => 'Label Final Check',
            'description' => 'Final labeling accuracy verification',
            'is_required' => true,
            'sort_order' => 5,
        ],
        [
            'layer' => 'qa',
            'title' => 'Client Requirements',
            'description' => 'Verify all client-specific requirements are met',
            'is_required' => true,
            'sort_order' => 6,
        ],
        [
            'layer' => 'qa',
            'title' => 'Delivery Standards',
            'description' => 'Ensure output meets delivery standards',
            'is_required' => true,
            'sort_order' => 7,
        ],
    ];

    protected array $photoEnhancementChecklist = [
        // Designer checklist items
        [
            'layer' => 'designer',
            'title' => 'Image Quality',
            'description' => 'Check image resolution and clarity',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'layer' => 'designer',
            'title' => 'Color Correction',
            'description' => 'Verify color balance and correction',
            'is_required' => true,
            'sort_order' => 2,
        ],
        [
            'layer' => 'designer',
            'title' => 'Enhancement Applied',
            'description' => 'Confirm all required enhancements are applied',
            'is_required' => true,
            'sort_order' => 3,
        ],
        [
            'layer' => 'designer',
            'title' => 'Retouching Complete',
            'description' => 'Verify all retouching work is complete',
            'is_required' => true,
            'sort_order' => 4,
        ],
        [
            'layer' => 'designer',
            'title' => 'Output Format',
            'description' => 'Confirm output is in correct format and size',
            'is_required' => true,
            'sort_order' => 5,
        ],
        
        // QA checklist items for photos
        [
            'layer' => 'qa',
            'title' => 'Final Quality Review',
            'description' => 'Final quality check for the enhanced photo',
            'is_required' => true,
            'sort_order' => 1,
        ],
        [
            'layer' => 'qa',
            'title' => 'Color Accuracy',
            'description' => 'Verify colors are accurate and appealing',
            'is_required' => true,
            'sort_order' => 2,
        ],
        [
            'layer' => 'qa',
            'title' => 'Enhancement Quality',
            'description' => 'Review quality of all enhancements applied',
            'is_required' => true,
            'sort_order' => 3,
        ],
        [
            'layer' => 'qa',
            'title' => 'Client Standards',
            'description' => 'Verify output meets client standards',
            'is_required' => true,
            'sort_order' => 4,
        ],
        [
            'layer' => 'qa',
            'title' => 'Delivery Ready',
            'description' => 'Confirm image is ready for delivery',
            'is_required' => true,
            'sort_order' => 5,
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projects = Project::all();

        foreach ($projects as $project) {
            // Choose checklist based on workflow type
            $checklist = $project->workflow_type === 'PH_2_LAYER' 
                ? $this->photoEnhancementChecklist 
                : $this->floorPlanChecklist;

            foreach ($checklist as $item) {
                ChecklistTemplate::firstOrCreate([
                    'project_id' => $project->id,
                    'layer' => $item['layer'],
                    'title' => $item['title'],
                ], [
                    'description' => $item['description'],
                    'is_required' => $item['is_required'],
                    'sort_order' => $item['sort_order'],
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info('Checklist templates seeded for ' . $projects->count() . ' projects.');
    }
}
