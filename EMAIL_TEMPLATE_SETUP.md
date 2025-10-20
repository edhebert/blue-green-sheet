# Email Template Setup Guide

## Overview
Custom HTML email templates have been created in `templates/_emails/` to match the Blue Green Sheet branding.

## Templates Created
1. **activate_account.html** - Account activation emails (green header)
2. **verify_email.html** - Email verification emails (blue header)
3. **forgot_password.html** - Password reset emails (blue header with warning box)
4. **test_email.html** - Test email template (green header with system info)

## Design Features
All templates use:
- **Inline CSS** for maximum email client compatibility
- **Arial font** for consistency
- **Color scheme**:
  - Green (#5cb85c) for positive actions and success
  - Blue (#337ab7) for admin/account actions
  - Yellow (#fcf8e3) for warnings
  - Gray (#f4f4f4) for backgrounds
- **Responsive design** with 600px max width
- **Button-style CTAs** with rounded corners and hover effects
- **HTML tables** for reliable layout across all email clients
- **Professional footer** with copyright and site link

## Configuration Steps

### 1. Configure in Craft CMS Control Panel

Go to **Settings → Email → Email Messages** in your Craft CMS control panel:

1. **Account Activation**
   - Template: `_emails/activate_account`

2. **Verify Email Address**
   - Template: `_emails/verify_email`

3. **Reset Password**
   - Template: `_emails/forgot_password`

4. **Test Email**
   - Template: `_emails/test_email`

### 2. Available Variables in Email Templates

Craft provides these variables automatically:
- `{{ user }}` - The user object
- `{{ user.friendlyName }}` or `{{ user.fullName }}` - User's name
- `{{ user.email }}` - User's email address
- `{{ link }}` - The action link (activation, verification, password reset)
- `{{ siteName }}` - Your site name
- `{{ siteUrl }}` - Your site URL
- `{{ systemEmail }}` - System email address
- `{{ systemName }}` - System name
- `{{ now }}` - Current date/time

### 3. Testing Your Templates

1. Go to **Utilities → System Messages** in the control panel
2. Click "Send a Test Email"
3. The test email will use your custom `test_email.html` template

## Matching Your Existing Notifications

These templates match the style of your custom admin notifications in `config/app.php`:

**Your existing notifications** (lines 166-181, 235-251, 667-709):
- New User Activated → Uses green (#5cb85c) header
- New Organization Added → Uses green (#5cb85c) header
- Job Posting Notifications → Uses green/blue/red based on payment type

**New system templates** use the same:
- Color scheme
- Font (Arial, sans-serif)
- Button styling (inline-block, rounded corners, padding)
- Table layouts for data
- Professional spacing and borders

## Notes

- Templates use `.html` extension for HTML emails
- All styles are inline for maximum compatibility
- Templates work with all email clients (Gmail, Outlook, Apple Mail, etc.)
- Mobile-responsive design with fluid tables
- No external CSS or JavaScript dependencies

## Customization

To customize further:
1. Edit the template files in `templates/_emails/`
2. Update colors in the inline `style` attributes
3. Modify button text, spacing, or layout as needed
4. Test changes using the Craft control panel test email feature

## Additional Email Types

If you need templates for other Craft email types, you can create:
- `_emails/account_activation.html`
- `_emails/email_changed.html`
- `_emails/password_changed.html`

Just follow the same structure and configure them in the control panel.
