<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Before one person could work in several shops, the same phone number could be added to
 * two shops as two separate staff sign-ins. This lists them so support can decide how to
 * merge each one (the person keeps one sign-in and resets their PIN). It changes nothing.
 */
class ReportDuplicateStaff extends Command
{
    protected $signature = 'staff:duplicates';

    protected $description = 'List phone numbers that are active staff under more than one sign-in (report only)';

    public function handle(): int
    {
        $groups = Employee::active()->whereNotNull('user_id')->with('shop:id,business_name')->get()
            ->groupBy(fn (Employee $employee) => PhoneNumber::normalize($employee->phone_number))
            ->filter(fn ($rows) => $rows->pluck('user_id')->unique()->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No phone number is staff under more than one sign-in.');

            return self::SUCCESS;
        }

        $this->table(
            ['Phone', 'Sign-ins (user id)', 'Shops'],
            $groups->map(fn ($rows, $phone) => [
                $phone,
                $rows->pluck('user_id')->unique()->implode(', '),
                $rows->map(fn (Employee $employee) => ($employee->shop?->business_name ?? '#'.$employee->shop_id).' (user '.$employee->user_id.')')->implode('; '),
            ])->values()->all(),
        );

        $this->warn($groups->count().' number(s) to merge by hand. See docs/employees.md, "Staff in several shops".');

        return self::SUCCESS;
    }
}
