<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles an organiser invents, invitations, and the audit log growing a memory.
 *
 * The built-in roles are not here. They are code (`Permissions::ROLES`), because a role whose
 * definition lives in a row is a role that drifts per tenant and cannot be reasoned about when a
 * permission is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // The key goes in `tenant_users.role`, alongside built-in keys, so membership has one
            // column and one resolution path. Reserved keys are refused at the API.
            $table->string('key');
            $table->string('name');
            $table->jsonb('permissions')->default('[]');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');

            // Hashed, like every other credential here: an invitation link is a way into an
            // account, and a leaked database should not be a set of working ones.
            $table->string('token_hash')->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            // Suspension rather than removal, so somebody who left keeps their name on what they
            // did. Deleting the membership would orphan every audit row that names them.
            $table->timestamp('suspended_at')->nullable()->after('last_seen_at');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            /*
             * What changed, not merely that something did.
             *
             * "Someone updated an event" answers nothing at four in the morning. Values are
             * redacted by AuditLogger on the way in — this column holds field names and, for
             * fields that are safe to keep, before and after.
             */
            $table->jsonb('changes')->nullable()->after('context');
            $table->string('subject_label')->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['changes', 'subject_label']);
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });

        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_roles');
    }
};
