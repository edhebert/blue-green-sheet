# Claude AI Assistant - Blue Green Sheet Project Guide

## Purpose of This File

This file contains instructions and context for AI assistants (like Claude) working on the Blue Green Sheet project. Read this file at the start of each new conversation to understand the project structure, conventions, and important considerations.

---

## Quick Start

1. **First, read**: `PROJECT_OVERVIEW.md` - Contains complete project documentation
2. **Then, read**: `EMAIL_TEMPLATE_SETUP.md` - Email template information (if working with emails)
3. **Check**: `.env` for environment-specific configuration (never commit this file)
4. **Key files**: `config/app.php` contains most custom logic and event handlers

---

## Project Context

**Blue Green Sheet** is a job board platform for educational institutions built on Craft CMS 5. Key features include:

- User registration with email verification
- Organization management (schools and recruiters)
- Job posting with payment processing (Stripe + Invoice)
- Automatic geographic region assignment
- Comprehensive admin notifications
- Custom Craft plugin for region derivation

---

## Technology Stack

- **CMS**: Craft CMS 5.x
- **PHP**: 8.2+
- **Database**: MySQL
- **Frontend**: Bootstrap 5 + Canvas template framework
- **Payment**: Stripe
- **Local Plugin**: Job Region Populator (`plugins/jobregionpopulator/`)

---

## Critical Files - DO NOT EDIT Without Careful Review

### 1. `config/app.php`
**Why it's critical**: Contains all custom event handlers, automation, email notifications, and payment processing logic.

**What's in it**:
- Lines 23-83: Auto-assignment to jobPosters group
- Lines 85-130: Custom Twig variable `craft.projectTools`
- Lines 132-287: Admin notification emails
- Lines 289-780: Job posting payment controller
- Lines 782-821: Auto-generate job slugs
- Lines 823-859: Store job ID in session for payment flow

**Before editing**: Understand the entire event flow to avoid breaking automation.

### 2. `plugins/jobregionpopulator/src/Plugin.php`
**Why it's critical**: Auto-populates geographic regions for all jobs based on state/country.

**What it does**:
- Maps US states to 12 geographic regions
- Handles international jobs
- Runs on `Entry::EVENT_BEFORE_SAVE` for jobs section

**Before editing**: Verify region mappings are still accurate with client.

### 3. `templates/profile.twig`
**Why it's critical**: Main user dashboard with complex state management and AJAX functionality.

**What's in it**:
- Lines 9-23: Flash message filtering (avoids showing stale Craft messages)
- Lines 25-39: Success message handling
- Lines 112-227: Job listing with publish/unpublish toggles
- Lines 638-764: JavaScript for job toggle functionality

**Before editing**: Test thoroughly - this page has many user-visible state changes.

---

## Custom Implementations to Be Aware Of

### 1. Job Region Auto-Population

Jobs automatically get assigned a geographic region when saved, based on their state and country fields.

**How it works**:
1. User selects country (dropdown field: `country`)
2. If US, user selects state (dropdown field: `jobState`)
3. On save, plugin looks up region from state mapping
4. Assigns category from `jobRegion` category group to job's `jobRegion` field

**Region mapping**: See `plugins/jobregionpopulator/src/Plugin.php` lines 85-156

**Important**: If client adds/changes regions, update the mapping in the plugin.

### 2. Job Slug Generation

Jobs get automatic slugs in format: `school-slug_job-title-slug`

**Example**: "Trinity Christian" + "Head of School" → `trinity-christian_head-of-school`

**Implementation**: `config/app.php` lines 782-821

**Why**: Creates readable, SEO-friendly URLs that include both school and position.

### 3. Payment Flow

Two payment methods:

**Invoice (Activate Immediately)**:
- Action: `job-posting/activate-job-posting`
- Sets job to enabled=true, paid=true
- Sends invoice request notification to admin
- User sees: "An invoice will soon be sent to your email"

**Stripe (Checkout Session)**:
- Action: `job-posting/payment-success` (callback)
- Verifies payment via Stripe API
- Sets job to enabled=true, paid=true
- Stores Stripe session ID

**Critical**: Jobs MUST be marked as `paid=true` to be published. The `enabled` status alone is not enough.

### 4. Email Notifications

All custom emails in `config/app.php` send to admin recipients defined by:
1. `ADMIN_NOTIFY_EMAILS` env variable (comma-separated)
2. Falls back to `SYSTEM_EMAIL` env variable
3. Hardcoded fallback: `ed+bgs@theblueocean.com`

**Before deploying**: Update the hardcoded fallback email address.

### 5. Flash Message Filtering

The profile page filters out default Craft CMS messages that don't make sense in context:
- "User saved" / "User registered"
- "Password reset email sent"

**Implementation**: `templates/profile.twig` lines 15, 31

**Why**: Craft's default messages are technical and confusing for end users.

---

## Coding Conventions

### Twig Templates

1. **Use semantic HTML** - Bootstrap 5 classes for layout
2. **Form handling** - Always include:
   ```twig
   {{ csrfInput() }}
   {{ actionInput('controller/action') }}
   {{ redirectInput('success-url') }}
   ```
3. **Error display** - Check for both field-specific errors and general flash messages
4. **User permissions** - Check `currentUser.admin` or `currentUser.isInGroup('groupHandle')`

### PHP (config/app.php)

1. **Event handlers** - Use static closures for better performance
2. **Logging** - Use `Craft::info()` and `Craft::error()` with method context
3. **Email HTML** - Always inline CSS, use tables for layout (email client compatibility)
4. **Error handling** - Try-catch blocks with user-friendly error messages

### CSS

1. **Custom styles** - Add to `web/css/custom.css`, NOT `web/style.css` (template file)
2. **Naming** - Follow existing conventions (BEM-like)
3. **Responsive** - Test on mobile (Bootstrap breakpoints: sm, md, lg, xl)

---

## Common Tasks & How to Do Them

### Add a New Admin Notification

1. Add event handler in `config/app.php` after line 287 (in admin notifications section)
2. Use `$resolveAdminRecipients()` helper to get email list
3. Build HTML email with inline styles (see existing examples)
4. Use `Craft::$app->getMailer()->send($msg)` to send

### Modify Region Mappings

1. Edit `plugins/jobregionpopulator/src/Plugin.php`
2. Update `$stateRegionMap` array (lines 85-156)
3. Ensure region category slugs match (check in Craft CP → Categories → Job Regions)
4. Test by creating/editing a job with the affected state

### Add a New User Group Auto-Assignment

1. Edit `config/app.php`
2. Add logic in the `$ensureJobPosters` closure (lines 24-41) or create new helper
3. Hook into same three events for redundancy (lines 44-83)

### Customize Email Templates

1. Edit files in `templates/_emails/`
2. Use inline CSS only (no external stylesheets)
3. Test with Utilities → System Messages → Send Test Email in Craft CP
4. See `EMAIL_TEMPLATE_SETUP.md` for available template variables

### Add Job Filtering

1. Edit `templates/jobs/index.twig`
2. Add filter UI in sidebar section (lines 88-194)
3. Update query building logic (lines 42-73)
4. Use `relatedTo` for category/entry relations
5. Use field filters for dropdown/text fields

---

## Debugging Tips

### Issue: User not getting added to jobPosters group

**Check**:
1. Is user created from front-end? (CP users are skipped)
2. Does 'jobPosters' group exist with correct handle?
3. Check Craft logs: `storage/logs/web.log`
4. Look for "ensureJobPosters" log entries

### Issue: Region not auto-populating for jobs

**Check**:
1. Is state field populated? (plugin needs this)
2. Is country set to 'unitedStates'?
3. Does region category exist with expected slug?
4. Check Craft logs for "Job Region Populator" entries
5. Verify plugin is installed: Craft CP → Settings → Plugins

### Issue: Payment not activating job

**Check**:
1. Is job marked as `paid=true`? (required)
2. Is job `enabled=true`? (required)
3. Check expiry date is set
4. Look for error logs in Craft logs
5. For Stripe: verify Stripe session ID was stored

### Issue: Email not sending

**Check**:
1. Email configured in Craft CP → Settings → Email
2. Test with Utilities → System Messages → Send Test Email
3. Check `SYSTEM_EMAIL` env variable is set
4. Check `ADMIN_NOTIFY_EMAILS` for admin notifications
5. Review error logs for mailer errors

### Issue: Flash messages showing wrong message

**Check**:
1. Is message filtered in profile.twig? (lines 15, 31)
2. Are you consuming flash correctly with `getFlash()`?
3. Check for `removeFlash()` calls that might be clearing too early
4. Use `getAllFlashes()` to peek without consuming (see profile.twig lines 11, 27)

---

## Testing Checklist

When making changes, test these critical paths:

### User Registration Flow
- [ ] New user can register
- [ ] Activation email received
- [ ] User can activate account
- [ ] User auto-assigned to jobPosters group
- [ ] Admin receives notification
- [ ] User can log in
- [ ] Welcome message displayed

### Organization Setup
- [ ] User prompted to select/create organization
- [ ] Existing organization search works
- [ ] New organization can be created
- [ ] Admin receives notification
- [ ] Thank you message displayed

### Job Posting Flow
- [ ] User can create job (form saves)
- [ ] Region auto-populates based on state
- [ ] Job slug generated correctly (school_title)
- [ ] Save as draft works (job stays disabled)
- [ ] Payment page displays correctly
- [ ] Invoice payment activates immediately
- [ ] Stripe payment processes and activates
- [ ] Notifications sent to admin and user

### Job Management
- [ ] Jobs display on profile page
- [ ] Status badges correct (Published/Unpublished/Incomplete)
- [ ] Toggle works for paid jobs
- [ ] Toggle disabled for unpaid jobs
- [ ] Expired jobs cannot be republished
- [ ] Edit works for job owner
- [ ] Preview works for unpublished jobs

### Password Reset
- [ ] Request form works
- [ ] Reset email received
- [ ] Can set new password
- [ ] Redirects to login
- [ ] Can log in with new password
- [ ] No stale flash messages on profile

---

## Environment-Specific Notes

### Development
- Use `.env.example.dev` as template
- Set `CRAFT_DEV_MODE=true`
- Set `CRAFT_ALLOW_ADMIN_CHANGES=true`
- Use Stripe test keys

### Staging
- Use `.env.example.staging` as template
- Set `CRAFT_DEV_MODE=false`
- Set `CRAFT_ALLOW_ADMIN_CHANGES=true` (for testing)
- Use Stripe test keys
- Update `ADMIN_NOTIFY_EMAILS` to test addresses

### Production
- Use `.env.example.production` as template
- Set `CRAFT_DEV_MODE=false`
- Set `CRAFT_ALLOW_ADMIN_CHANGES=false` (after launch)
- Use Stripe live keys
- Update `ADMIN_NOTIFY_EMAILS` to real admin emails
- Update hardcoded email in `config/app.php` lines 148, 648

---

## Database Backup & Migrations

### Before Major Changes
```bash
# Backup database
php craft db/backup

# Export project config
php craft project-config/rebuild
```

### After Schema Changes
```bash
# Generate migration from changes
php craft migrate/create migration_name

# Apply changes to project config
php craft project-config/apply
```

---

## Questions to Ask the Client

Before making significant changes, clarify:

1. **Regions**: Are the geographic region groupings still accurate?
2. **Pricing**: Are job posting prices ($300/$400) still correct?
3. **Payment flow**: Should jobs activate immediately on invoice, or wait for payment?
4. **Organization approval**: Should new organizations require admin approval?
5. **Job expiry**: Any automated expiry reminders needed?
6. **Permissions**: Should all jobPosters be able to post immediately?
7. **Email recipients**: Who should receive admin notifications?

---

## Known Limitations & Gotchas

### 1. Multi-site Consideration
The organization notification has de-duplication logic (lines 215-224) but assumes single-site. If adding multi-site support, review these handlers.

### 2. Stripe Webhook
Currently using redirect-based Stripe Checkout. For production reliability, consider implementing Stripe webhooks to handle:
- Delayed payment confirmation
- Payment failures
- Subscription renewals (if adding recurring payments)

### 3. Job Expiry
Jobs have an `expiryDate` field but no automated disabling. Consider adding:
- Scheduled task to disable expired jobs
- Email reminders before expiry
- Renewal workflow

### 4. Organization Ownership
Multiple users can be linked to the same organization. There's no "owner" concept or organization-level permissions beyond individual job authorship.

### 5. Search/SEO
Job board has filtering but no full-text search. For better UX at scale, consider:
- Elasticsearch or similar
- Full-text search on job title/description
- Saved search/job alerts

---

## Recent Fixes (October 2024)

Document any bugs fixed or improvements made:

1. **Duplicate email error on registration** - Fixed by excluding `unverifiedEmail` attribute from error display (register.twig line 96)

2. **Login errors not showing** - Removed premature `removeFlash('error')` call from login.twig

3. **Stale password reset message** - Added filter for "Password reset" in profile.twig flash message handling

4. **Recruiter info alignment** - Added flexbox to `.card-body.tombstone` to align "Search being conducted by" text to bottom of cards (custom.css lines 44-52)

---

## Useful Craft CLI Commands

```bash
# Clear all caches
php craft clear-caches/all

# Run queue jobs (for email sending, etc.)
php craft queue/run

# Rebuild search indexes
php craft resave/entries --section=jobs

# Check for Craft updates
php craft update

# Database backup
php craft db/backup

# Project config rebuild
php craft project-config/rebuild
```

---

## File Structure Quick Reference

```
blue-green-sheet/
├── config/
│   ├── app.php                 # Custom event handlers & controllers ⚠️
│   └── general.php             # General Craft settings
├── plugins/
│   └── jobregionpopulator/     # Custom region plugin ⚠️
│       ├── composer.json
│       └── src/Plugin.php
├── templates/
│   ├── _layout.twig            # Main layout
│   ├── _emails/                # Email templates
│   ├── jobs/                   # Job posting templates
│   │   ├── index.twig         # Job board
│   │   ├── post.twig          # Create job
│   │   ├── edit.twig          # Edit job
│   │   ├── payment.twig       # Payment page
│   │   └── _entry.twig        # Job detail
│   ├── profile/                # User profile & org management
│   │   ├── select-organization.twig
│   │   └── edit-organization.twig
│   ├── register.twig           # User registration
│   ├── login.twig              # Login
│   ├── forgot-password.twig    # Password reset request
│   ├── setpassword.twig        # Set new password
│   └── profile.twig            # User dashboard ⚠️
├── web/
│   ├── css/
│   │   └── custom.css          # Custom styles (edit here)
│   ├── style.css               # Template styles (don't edit)
│   └── js/
├── .env                        # Environment config (never commit) ⚠️
├── composer.json               # PHP dependencies
├── PROJECT_OVERVIEW.md         # Project documentation 📖
├── CLAUDE.md                   # This file 📖
└── EMAIL_TEMPLATE_SETUP.md     # Email setup guide 📖
```

⚠️ = Critical files - review carefully before editing
📖 = Documentation - read first

---

## When in Doubt

1. **Check PROJECT_OVERVIEW.md** for architectural decisions
2. **Review existing code** for patterns and conventions
3. **Test thoroughly** - this is a production application
4. **Ask the client** before making assumptions about business logic
5. **Document your changes** - update this file if you add new patterns

---

**Remember**: This is a live job board serving real users. Always err on the side of caution, test thoroughly, and maintain backward compatibility.

---

**Last Updated**: October 2024
**Project Start**: 2024
**Craft CMS Version**: 5.x
