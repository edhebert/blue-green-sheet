# Blue Green Sheet - Project Overview

## Table of Contents
1. [Project Description](#project-description)
2. [Technology Stack](#technology-stack)
3. [Custom Implementations](#custom-implementations)
4. [Database Structure](#database-structure)
5. [User Flows](#user-flows)
6. [Job Posting System](#job-posting-system)
7. [Email System](#email-system)
8. [Environment Configuration](#environment-configuration)
9. [Key Files Reference](#key-files-reference)

---

## Project Description

Blue Green Sheet is a job board platform built on Craft CMS 5, specifically designed for educational institutions and recruiters to post teaching and administrative positions. The platform features:

- **User registration and authentication** with email verification
- **Organization management** (Schools and Recruiting Firms)
- **Job posting and payment** (Stripe and Invoice options)
- **Geographic region filtering** with automatic population
- **Administrative notifications** for all key user actions
- **Custom Craft CMS plugin** for region derivation

---

## Technology Stack

- **CMS**: Craft CMS 5
- **PHP Version**: 8.2+
- **Database**: MySQL
- **Frontend**: Bootstrap 5 + Custom CSS (Canvas template framework)
- **Payment Processing**: Stripe
- **Email**: Craft's native mail system with custom HTML templates
- **Custom Plugin**: Job Region Populator (local plugin)

---

## Custom Implementations

### 1. Job Region Populator Plugin

**Location**: `plugins/jobregionpopulator/`

**Purpose**: Automatically derives and populates the geographic region for job postings based on country and state selections.

**How it works**:
- Listens to `Entry::EVENT_BEFORE_SAVE` for entries in the 'jobs' section
- Reads the `country` and `jobState` field values
- Maps US states to geographic regions (Pacific, Mid-Atlantic, Southeast, etc.)
- Automatically assigns the appropriate region category to the `jobRegion` field
- Handles international jobs by assigning them to the 'international' region

**Region Mappings**:
- **Pacific**: CA, OR, WA, AK, HI
- **Mid-Atlantic**: DC, DE, MD, VA, WV
- **Southeast**: FL, GA, NC, SC
- **South Central East**: AL, KY, MS, TN
- **South Central West**: AR, LA, OK, TX
- **Great Lakes**: IL, IN, MI, OH, WI
- **New England**: CT, ME, MA, NH, RI, VT
- **Tri-State**: NY, NJ, PA
- **Central**: IA, KS, MN, MO, NE, ND, SD
- **Mountain**: CO, ID, MT, UT, WY
- **Southwest**: AZ, NM, NV
- **International**: All non-US jobs

### 2. Automatic User Group Assignment

**Location**: `config/app.php` (lines 23-83)

**Purpose**: Automatically assigns new users to the 'jobPosters' group upon registration.

**Implementation**:
- Hooks into three events for redundancy:
  1. `Elements::EVENT_AFTER_SAVE_ELEMENT` - Catches new users on registration
  2. `UsersService::EVENT_AFTER_VERIFY_EMAIL` - Assigns after email verification
  3. `UsersService::EVENT_AFTER_ACTIVATE_USER` - Final safety net on activation
- Only processes users created from the front-end (not Control Panel)
- Preserves existing group memberships while adding jobPosters group

### 3. Custom Twig Variable: `craft.projectTools`

**Location**: `config/app.php` (lines 85-130)

**Purpose**: Exposes Craft field options to Twig templates for dynamic form generation.

**Usage**:
```twig
{% set options = craft.projectTools.options('fieldHandle') %}
{% for option in options %}
    <option value="{{ option.value }}" {% if option.default %}selected{% endif %}>
        {{ option.label }}
    </option>
{% endfor %}
```

**Supports**:
- Dropdown fields
- Radio button fields
- Returns array with `label`, `value`, and `default` properties

### 4. Job Posting Payment Controller

**Location**: `config/app.php` (lines 289-780)

**Purpose**: Custom controller to handle job posting activation and payment processing.

**Actions**:

1. **`job-posting/activate-job-posting`** (Invoice Payment)
   - Immediately activates job posting
   - Sets `paid=true`, `paymentType='invoice'`
   - Calculates expiry date based on duration (6 or 12 months)
   - Sends notifications to admin and job poster

2. **`job-posting/payment-success`** (Stripe Payment)
   - Callback after successful Stripe Checkout
   - Verifies payment via Stripe API
   - Activates job posting
   - Sets `paid=true`, `paymentType='stripe'`
   - Stores Stripe session ID for tracking

3. **`job-posting/process-stripe-payment`** (Direct Stripe)
   - Direct payment processing (alternative to Checkout)
   - Handles 3D Secure authentication
   - Creates Stripe Payment Intent

**Payment Flow**:
```
User creates job → Saves as draft → Redirects to payment page →
  → (Invoice) Activates immediately, sends invoice request to admin
  → (Stripe) Processes payment → Activates on success
```

### 5. Auto-generate Job Slugs

**Location**: `config/app.php` (lines 782-821)

**Purpose**: Automatically generates job entry slugs in format: `school-slug_job-title-slug`

**Example**: Trinity Christian + "Head of School" → `trinity-christian_head-of-school`

### 6. Admin Notification Emails

**Location**: `config/app.php` (lines 132-287)

**Notifications sent**:

1. **New User Activated** (lines 151-192)
   - Sent when user activates account
   - Includes link to user in Control Panel
   - Sets welcome message for user

2. **New Organization Added** (lines 194-270)
   - Sent when organization entry is created
   - De-duplicates across multi-site saves
   - Includes links to public page and CP edit page
   - Sets thank you message for user

3. **First Login Without Organization** (lines 272-285)
   - Detects when user logs in without organization
   - Prompts them to select/create organization

**Email Recipients**:
- Configured via `ADMIN_NOTIFY_EMAILS` environment variable (comma-separated)
- Falls back to `SYSTEM_EMAIL` if not set
- Hardcoded fallback: `ed+bgs@theblueocean.com`

---

## Database Structure

### Content Sections

1. **Jobs**
   - Handle: `jobs`
   - Key Fields:
     - `title` - Job title
     - `school` (Entry relation) - Related school/organization
     - `jobState` (Dropdown) - US state
     - `country` (Dropdown) - Country selection
     - `jobRegion` (Category) - Auto-populated geographic region
     - `jobCategory` (Category) - Job type/category
     - `paid` (Lightswitch) - Payment status
     - `paymentType` (Dropdown) - 'stripe' or 'invoice'
     - `stripeSessionId` (Plain Text) - Stripe transaction ID
     - `expiryDate` (Date) - When job expires
     - `enabled` (Boolean) - Published status

2. **Organizations**
   - Handle: `organizations`
   - Key Fields:
     - `title` - Organization name
     - `organizationType` (Dropdown) - 'school' or 'recruiter'
     - `website` (URL)
     - Logo/branding fields

### User Fields

- **organization** (Entry relation) - Links user to their organization
- Users belong to `jobPosters` group by default

### Categories

1. **Job Regions** (`jobRegion`)
   - Geographic regions (Pacific, Mid-Atlantic, etc.)
   - International

2. **Job Categories** (`jobCategory`)
   - Job types (Academic Leadership, Teaching, etc.)

---

## User Flows

### Registration Flow

1. User visits `/register`
2. Fills out registration form:
   - Email
   - First name
   - Last name
   - Password
3. Craft CMS creates user account (pending activation)
4. User assigned to `jobPosters` group automatically
5. Activation email sent to user
6. User clicks activation link in email
7. Admin receives "New User Activated" notification
8. User sees welcome message and is redirected to login
9. User logs in and is prompted to select/create organization

**Key Files**:
- `templates/register.twig` - Registration form
- `templates/_emails/activate_account.html` - Activation email template
- `config/app.php` (lines 23-83, 151-192) - Auto-assignment and notifications

### Organization Setup Flow

1. After first login, user sees message: "Select or create your organization"
2. User navigates to `/profile/select-organization`
3. Two options:
   - **Search existing organization** - Type-ahead search
   - **Create new organization** - Form to add organization
4. On organization creation:
   - Entry created in 'organizations' section
   - User's `organization` field linked to new entry
   - Admin receives "New Organization Added" notification
   - User sees thank you message
5. User can now post jobs

**Key Files**:
- `templates/profile/select-organization.twig` - Organization selection UI
- `templates/profile/edit-organization.twig` - Edit organization details
- `config/app.php` (lines 194-270) - New organization notifications

### Password Reset Flow

1. User visits `/forgot-password`
2. Enters email address
3. Craft sends password reset email
4. User clicks link → lands on `/setpassword?code=XXX&id=YYY`
5. User enters new password
6. Redirects to `/login`
7. Flash message: "Password reset email sent." (filtered out on profile page)
8. User logs in with new password

**Key Files**:
- `templates/forgot-password.twig` - Request reset form
- `templates/setpassword.twig` - Set new password form
- `templates/login.twig` - Login form (fixed to show errors)
- `templates/_emails/forgot_password.html` - Reset email template
- `templates/profile.twig` (lines 15, 31) - Filters out password reset flash messages

**Recent Fixes**:
- Removed `{% do craft.app.session.removeFlash('error') %}` from login.twig to show error messages
- Added "Password reset" filter to profile.twig to prevent showing stale messages

---

## Job Posting System

### Job Creation Flow

1. User (logged in, organization set) navigates to `/jobs/post`
2. Fills out job posting form:
   - Job title
   - School (if recruiter) or defaults to their organization
   - Location (city, state, country)
   - Job category
   - Description, requirements, etc.
3. Two submit options:
   - **Save as Draft** - Saves job (disabled, unpaid)
   - **Continue to Payment** - Saves job and redirects to payment
4. If continuing to payment:
   - Job ID stored in session as `pendingJobId`
   - Redirects to `/jobs/payment`
5. Payment page offers:
   - **6-month listing**: $300
   - **12-month listing**: $400
   - Payment methods: Stripe or Invoice
6. On payment completion:
   - Job activated (`enabled=true`, `paid=true`)
   - Expiry date set
   - Notifications sent to admin and user
7. Job appears on public job board

**Key Files**:
- `templates/jobs/post.twig` - Job creation form
- `templates/jobs/payment.twig` - Payment selection page
- `config/app.php` (lines 289-780, 823-859) - Payment controller and session handling

### Job Management

**From Profile Page** (`/profile`):
- Users see all their job postings
- Each job shows:
  - Status badge (Published/Unpublished/Incomplete)
  - Post date and expiry date
  - View/Preview and Edit buttons
  - Publish/Unpublish toggle (for paid jobs only)

**Toggle Functionality**:
- AJAX toggle to enable/disable jobs
- Only available for paid jobs
- Expired jobs cannot be republished
- Updates status badge and button text dynamically

**Editing Jobs**:
- `/jobs/edit/{jobId}` - Edit existing job
- Only accessible by job author or admin
- Can update all job details except payment status

**Key Files**:
- `templates/profile.twig` (lines 112-227) - Job listing and toggle
- `templates/jobs/edit.twig` - Job editing form
- `templates/jobs/preview.twig` - Preview unpublished jobs

### Job Slug Generation

Jobs automatically get slugs in the format: `school-slug_job-title-slug`

**Example**:
- School: "Trinity Christian" (slug: `trinity-christian`)
- Job Title: "Head of School" (slug: `head-of-school`)
- Final slug: `trinity-christian_head-of-school`

**Implementation**: `config/app.php` (lines 782-821)

---

## Email System

### System Email Templates

**Location**: `templates/_emails/`

All templates use:
- Inline CSS for email client compatibility
- Responsive design (600px max width)
- Consistent branding (green/blue color scheme)
- Professional HTML table layouts

**Templates**:

1. **activate_account.html**
   - Green header (#5cb85c)
   - Sent when user activates account
   - Includes activation link button

2. **verify_email.html**
   - Blue header (#337ab7)
   - Sent for email verification
   - Includes verification link button

3. **forgot_password.html**
   - Blue header with yellow warning box
   - Sent for password reset requests
   - Includes reset link button

4. **test_email.html**
   - Green header
   - Used for system email tests
   - Shows system information

**Configuration**: See `EMAIL_TEMPLATE_SETUP.md` for full details

### Transactional Emails (Programmatic)

**Location**: Defined in `config/app.php`

1. **New User Activated** (lines 151-192)
   - To: Admin(s)
   - Subject: "New user activated"
   - Includes user details and CP link

2. **New Organization Added** (lines 194-270)
   - To: Admin(s)
   - Subject: "New organization added"
   - Includes organization details and links

3. **Job Posting Notifications** (lines 634-768)
   - **Invoice Request** (to admin):
     - Subject: "Invoice Request - New Job Posting: [Title]"
     - Red header (#d9534f)
     - Includes ACTION REQUIRED box
   - **Job Published** (to admin):
     - Subject: "New Job Posting: [Title]"
     - Green header
     - Includes job and payment details
   - **Confirmation** (to job poster):
     - Subject: "Your Job Posting is Now Live: [Title]"
     - Green header
     - Includes job details and public URL

---

## Environment Configuration

### Required Environment Variables

**Core Craft Settings**:
```env
CRAFT_APP_ID=              # Unique application ID
CRAFT_ENVIRONMENT=         # dev, staging, production
CRAFT_SECURITY_KEY=        # Security key for encryption
CRAFT_DEV_MODE=           # true/false
```

**Database**:
```env
CRAFT_DB_DRIVER=mysql
CRAFT_DB_SERVER=127.0.0.1
CRAFT_DB_PORT=3306
CRAFT_DB_DATABASE=
CRAFT_DB_USER=
CRAFT_DB_PASSWORD=
```

**Email**:
```env
SYSTEM_EMAIL=             # From address for system emails
```

**Custom Variables**:
```env
ADMIN_NOTIFY_EMAILS=      # Comma-separated list for admin notifications
STRIPE_PUBLIC_KEY=        # Stripe publishable key
STRIPE_SECRET_KEY=        # Stripe secret key
```

**Example Files**:
- `.env.example.dev` - Development environment
- `.env.example.staging` - Staging environment
- `.env.example.production` - Production environment

---

## Key Files Reference

### Configuration Files

- **`config/app.php`** - Core application bootstrap with custom event handlers, controllers, and automation
- **`config/general.php`** - General Craft CMS settings (usernames, aliases, etc.)
- **`composer.json`** - PHP dependencies and autoloading
- **`.env`** - Environment variables (not in git)

### Custom Plugin

- **`plugins/jobregionpopulator/src/Plugin.php`** - Region auto-population logic
- **`plugins/jobregionpopulator/composer.json`** - Plugin metadata

### Templates

**Layouts**:
- **`templates/_layout.twig`** - Main site layout template

**User Management**:
- **`templates/register.twig`** - User registration form
- **`templates/login.twig`** - Login form
- **`templates/forgot-password.twig`** - Password reset request
- **`templates/setpassword.twig`** - Set new password
- **`templates/profile.twig`** - User profile and job dashboard
- **`templates/profile/select-organization.twig`** - Organization selection/creation
- **`templates/profile/edit-organization.twig`** - Edit organization

**Jobs**:
- **`templates/jobs/index.twig`** - Job board with filtering
- **`templates/jobs/post.twig`** - Create new job posting
- **`templates/jobs/edit.twig`** - Edit existing job
- **`templates/jobs/payment.twig`** - Payment selection page
- **`templates/jobs/preview.twig`** - Preview unpublished jobs
- **`templates/jobs/_entry.twig`** - Individual job display

**Organizations**:
- **`templates/organizations/_entry.twig`** - Organization profile page

**Emails**:
- **`templates/_emails/activate_account.html`** - Account activation email
- **`templates/_emails/verify_email.html`** - Email verification
- **`templates/_emails/forgot_password.html`** - Password reset email
- **`templates/_emails/test_email.html`** - Test email template

### Frontend Assets

**CSS**:
- **`web/style.css`** - Main template styles (Canvas framework)
- **`web/css/custom.css`** - Custom project-specific styles

**JavaScript**:
- **`web/js/modules/`** - Canvas framework modules (menus, sliders, etc.)

### Documentation

- **`EMAIL_TEMPLATE_SETUP.md`** - Email template configuration guide
- **`SYSTEM_EMAIL_SETUP.md`** - System email setup documentation
- **`PROJECT_OVERVIEW.md`** (this file) - Complete project documentation
- **`CLAUDE.md`** - AI assistant instructions for future development

---

## Recent Bug Fixes and Improvements

### Registration & Login
1. **Duplicate email error** - Fixed showing twice on registration by filtering out `unverifiedEmail` errors
2. **Login error not showing** - Removed premature flash error clearing in login.twig
3. **Password reset message persisting** - Added filter to prevent "Password reset email sent" from showing on profile page

### Job Display
1. **Recruiter paragraph alignment** - Used flexbox to align "Search being conducted by..." text to bottom of job cards for consistency

### Session Management
- Proper flash message handling throughout user flows
- Flash message filtering on profile page to avoid showing stale Craft CMS messages

### Stripe Integration
- Using Craft CMS Stripe plugin for payment processing
- Test mode verified working with test card (4242 4242 4242 4242)
- Test pages created: `templates/stripe-test.twig` and `templates/stripe-test-success.twig`
- Transactions visible in Stripe Dashboard → Transactions (not Analytics)

---

## Stripe Payment Testing

### Test Pages (For Production Verification)

Two template files exist for testing Stripe integration with live keys:

- **`templates/stripe-test.twig`** - $1 test payment form
- **`templates/stripe-test-success.twig`** - Success confirmation page

**Purpose**: Verify Stripe live keys work before switching production job payments to live mode.

**How to Use**:
1. Ensure test product exists: "One Dollar Test Product" ($1.00) in Stripe
2. Sync Stripe data: Craft CP → Settings → Stripe → Sync
3. Visit `/stripe-test` when logged in
4. Process $1 payment (test mode uses 4242 4242 4242 4242, live mode uses real card)
5. Verify transaction in Stripe Dashboard → Transactions
6. Refund $1 if using live mode
7. Delete test templates when satisfied

**Important Notes**:
- Test pages use same Stripe plugin pattern as production (`stripe/checkout` action)
- URLs must be hashed using `|hash` filter for Craft security
- Stripe Dashboard: Use "Transactions" menu (not Payments → Analytics) to view test transactions
- Toggle between Test/Live mode in Stripe Dashboard (top-right corner)

### Craft CMS Stripe Plugin

**Documentation**: https://github.com/craftcms/stripe

**Key Concepts**:
- Products and Prices are Craft elements (queryable via `craft.stripeProducts`)
- Data syncs via webhook or manual sync (Settings → Stripe → Sync button)
- Checkout uses `actionInput('stripe/checkout')` with `lineItems[0][price]` parameter
- Price IDs auto-synced from Stripe (match by `unit_amount` in cents)

**Environment Variables Required**:
```env
STRIPE_PUBLISHABLE_KEY=pk_test_xxxxx  # or pk_live_xxxxx
STRIPE_SECRET_KEY=sk_test_xxxxx       # or sk_live_xxxxx
```

**Current Implementation** (`templates/jobs/payment.twig`):
- Queries Stripe products via `craft.stripeProducts.all()`
- Matches prices by amount: 30000 cents = $300, 40000 cents = $400
- Uses `stripe/checkout` action for Stripe-hosted checkout
- Success callback: `actions/job-posting/payment-success`

**Price Matching Pattern** (applies to all payment forms):
```twig
{% for product in craft.stripeProducts.all() %}
  {% for price in product.prices %}
    {# Match by BOTH price AND product title prefix #}
    {% if price.data.unit_amount == 100 and product.title starts with 'BGS' %}     {# $1.00 test #}
    {% if price.unit_amount == 30000 and product.title starts with 'BGS' %}       {# $300 6-month #}
    {% if price.unit_amount == 40000 and product.title starts with 'BGS' %}       {# $400 12-month #}
  {% endfor %}
{% endfor %}
```

**IMPORTANT: Product Naming Convention**
- All Blue Green Sheet products in Stripe MUST have titles starting with "BGS"
- This prevents conflicts with other products in the client's Stripe account (from other websites)
- Example product names: "BGS - 6 Month Job Listing", "BGS - Test Product", etc.

**Switching Between Test/Live Mode**:
- **No code changes required** - all forms match by price amount + "BGS" prefix
- When switching to live mode, create products in live Stripe Dashboard with these criteria:
  - $1.00 with title starting with "BGS" (for testing)
  - $300.00 with title starting with "BGS" (6-month job listing)
  - $400.00 with title starting with "BGS" (12-month job listing)
- Sync in Craft CP (Settings → Stripe → Sync)
- Forms automatically find correct products by matching both `unit_amount` and title prefix
- Product IDs and full names can differ between test/live - only price and "BGS" prefix matter

---

## Future Considerations

### Questions to Ask When Returning to This Project

1. **Geographic Regions**: Are the region groupings still accurate? Any new regions needed?
2. **Payment Amounts**: Are the $300/$400 pricing tiers still correct?
3. **Email Recipients**: Update `ADMIN_NOTIFY_EMAILS` environment variable as team changes
4. **Stripe Integration**: Review Stripe webhook setup for production reliability
5. **Job Expiry**: Consider automated job expiry reminders or renewal system
6. **Search/Filtering**: Any additional filters needed on job board?
7. **Organization Approval**: Should new organizations require admin approval before posting?

---

**Last Updated**: October 2024
**Craft CMS Version**: 5.x
**PHP Version**: 8.2+
