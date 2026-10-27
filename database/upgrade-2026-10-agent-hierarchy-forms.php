<?php
/**
 * database/upgrade-2026-10-agent-hierarchy-forms.php
 *
 * Non-destructive schema additions for:
 * 1. 4-Tier Organization & Agent Hierarchy (Master Agent, Sub-Agent/Agent Staff, Company Staff)
 * 2. Booking attribution to both Sub-Agent and Parent Agent
 * 3. Digital KYC & Agreement correction request tracking
 * 4. Parcel/luggage tagging and border ID verification flags
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

try {
    echo "Running migration upgrade-2026-10-agent-hierarchy-forms...\n";

    // 1. admin_profiles additions
    $cols = array_column(Database::fetchAll('DESCRIBE admin_profiles'), 'Field');
    if (!in_array('parent_agent_id', $cols, true)) {
        Database::run('ALTER TABLE admin_profiles ADD COLUMN parent_agent_id BIGINT UNSIGNED NULL AFTER admin_id');
        echo "Added parent_agent_id to admin_profiles\n";
    }
    if (!in_array('duty_status', $cols, true)) {
        Database::run("ALTER TABLE admin_profiles ADD COLUMN duty_status ENUM('on_duty','off_duty','on_leave') NOT NULL DEFAULT 'on_duty' AFTER notes");
        echo "Added duty_status to admin_profiles\n";
    }
    if (!in_array('monthly_salary', $cols, true)) {
        Database::run('ALTER TABLE admin_profiles ADD COLUMN monthly_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cash_limit');
        echo "Added monthly_salary to admin_profiles\n";
    }
    if (!in_array('assigned_bus_id', $cols, true)) {
        Database::run('ALTER TABLE admin_profiles ADD COLUMN assigned_bus_id BIGINT UNSIGNED NULL AFTER parent_agent_id');
        echo "Added assigned_bus_id to admin_profiles\n";
    }

    // 2. bookings additions
    $bcols = array_column(Database::fetchAll('DESCRIBE bookings'), 'Field');
    if (!in_array('sub_agent_admin_id', $bcols, true)) {
        Database::run('ALTER TABLE bookings ADD COLUMN sub_agent_admin_id BIGINT UNSIGNED NULL AFTER sold_by_admin_id');
        echo "Added sub_agent_admin_id to bookings\n";
    }
    if (!in_array('parent_agent_id', $bcols, true)) {
        Database::run('ALTER TABLE bookings ADD COLUMN parent_agent_id BIGINT UNSIGNED NULL AFTER sub_agent_admin_id');
        echo "Added parent_agent_id to bookings\n";
    }
    if (!in_array('parcel_tag', $bcols, true)) {
        Database::run('ALTER TABLE bookings ADD COLUMN parcel_tag VARCHAR(50) NULL AFTER cabin_label');
        echo "Added parcel_tag to bookings\n";
    }
    if (!in_array('border_id_verified', $bcols, true)) {
        Database::run('ALTER TABLE bookings ADD COLUMN border_id_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_cod');
        echo "Added border_id_verified to bookings\n";
    }

    // 3. agent_applications additions
    if (Database::exists("SHOW TABLES LIKE 'agent_applications'")) {
        $acols = array_column(Database::fetchAll('DESCRIBE agent_applications'), 'Field');
        if (!in_array('role_category', $acols, true)) {
            Database::run("ALTER TABLE agent_applications ADD COLUMN role_category ENUM('staff','agent','subagent','partner') NOT NULL DEFAULT 'agent' AFTER type");
            echo "Added role_category to agent_applications\n";
        }
        if (!in_array('parent_agent_id', $acols, true)) {
            Database::run('ALTER TABLE agent_applications ADD COLUMN parent_agent_id BIGINT UNSIGNED NULL AFTER admin_id');
            echo "Added parent_agent_id to agent_applications\n";
        }
        if (!in_array('correction_notes', $acols, true)) {
            Database::run('ALTER TABLE agent_applications ADD COLUMN correction_notes TEXT NULL AFTER note');
            echo "Added correction_notes to agent_applications\n";
        }
        if (!in_array('correction_fields', $acols, true)) {
            Database::run('ALTER TABLE agent_applications ADD COLUMN correction_fields VARCHAR(255) NULL AFTER correction_notes');
            echo "Added correction_fields to agent_applications\n";
        }
    }

    // 4. staff_applications additions if table exists
    if (Database::exists("SHOW TABLES LIKE 'staff_applications'")) {
        $scols = array_column(Database::fetchAll('DESCRIBE staff_applications'), 'Field');
        if (!in_array('correction_notes', $scols, true)) {
            Database::run('ALTER TABLE staff_applications ADD COLUMN correction_notes TEXT NULL AFTER office_note');
            echo "Added correction_notes to staff_applications\n";
        }
        if (!in_array('correction_fields', $scols, true)) {
            Database::run('ALTER TABLE staff_applications ADD COLUMN correction_fields VARCHAR(255) NULL AFTER correction_notes');
            echo "Added correction_fields to staff_applications\n";
        }
    }

    echo "SUCCESS: All migration steps completed successfully.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
