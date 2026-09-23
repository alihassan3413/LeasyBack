<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Support\TaskPriorityRule;

class OrderTaskPriorityRules
{
    /**
     * @return array<string, TaskPriorityRule>
     */
    protected function definitions(): array
    {
        return [
            'confirm_inspection_appointment' => TaskPriorityRule::none(),
            'upload_initial_appraisal' => TaskPriorityRule::afterAppointmentHours(24, 48),
            'complete_initial_appraisal' => TaskPriorityRule::immediate(),
            'capture_repair_positions' => TaskPriorityRule::immediate(),
            'request_workshop_quotations' => TaskPriorityRule::immediate(),
            'await_workshop_quotations' => TaskPriorityRule::afterHours(24, 48),
            'create_customer_offer' => TaskPriorityRule::immediate(),
            'publish_customer_offer' => TaskPriorityRule::immediate(),
            'renew_expired_offer' => TaskPriorityRule::none(),
            'commission_workshop' => TaskPriorityRule::immediate(),
            'set_repair_appointment' => TaskPriorityRule::immediate(),
            'await_repair' => TaskPriorityRule::afterDays(2, 3),
            'upload_final_appraisal' => TaskPriorityRule::afterAppointmentHours(24, 48),
            'evaluate_reinspection' => TaskPriorityRule::immediate(),
            'await_repair_payment' => TaskPriorityRule::afterHours(24, 48),
            'confirm_pickup' => TaskPriorityRule::afterHours(24, 48),
            DetachedOrderTaskResolver::CALL_CUSTOMER_ABOUT_PENDING_OFFER => TaskPriorityRule::immediate(),
            DetachedOrderTaskResolver::CALL_CUSTOMER_ABOUT_PENDING_PAYMENT => TaskPriorityRule::immediate(),
        ];
    }

    public function for(string $taskKey): TaskPriorityRule
    {
        return $this->definitions()[$taskKey] ?? TaskPriorityRule::none();
    }
}
