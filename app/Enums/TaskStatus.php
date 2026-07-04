<?php

namespace App\Enums;

/**
 * TaskStatus — values taken verbatim from the master plan PDF, Section
 * 33, "Task" row: "open; in_progress; blocked; completed; cancelled;
 * overdue". "Blocked derives from unmet dependencies; overdue is
 * derived from due_at and status, not manually trusted" (same PDF
 * row) — TaskDependencyService is the only place Blocked is set/
 * cleared, and TaskService derives Overdue rather than accepting it as
 * a directly-settable value.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Overdue = 'overdue';
}
