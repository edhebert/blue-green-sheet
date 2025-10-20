# System Email Setup Guide

## Overview
This guide shows you how to configure Craft CMS to send professional HTML emails that match your Blue Green Sheet branding.

## Step 1: Configure the HTML Email Template

1. Go to **Settings → Email** in the Craft CMS control panel
2. Scroll down to the **HTML Email Template** field
3. Enter: `_emails/email-layout`
4. Click **Save**

This tells Craft to wrap all system emails with your custom HTML template.

## Step 2: Update System Messages with HTML

Go to **Utilities → System Messages** and update each message with HTML formatting:

### Activate Account Message

Click the pencil icon next to "Activate Account" and replace with:

```html
<p>Hey {{user.friendlyName|e}},</p>

<p>Welcome to {{systemName}}! To activate your job posting account, click the button below:</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{link}}" style="display: inline-block; padding: 15px 40px; background-color: #5cb85c; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">Activate Your Account</a>
</p>

<p style="font-size: 14px; color: #666666;">Or copy and paste this URL into your browser:<br>
<a href="{{link}}" style="color: #337ab7; word-break: break-all;">{{link}}</a></p>

<p style="margin-top: 20px; font-size: 14px; color: #666666;">If you were not expecting this email, just ignore it.</p>
```

### Verify Email Message

Click the pencil icon next to "Verify Email" and replace with:

```html
<p>Hey {{user.friendlyName|e}},</p>

<p>Please verify your email address to complete your Blue Green Sheet registration:</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{link}}" style="display: inline-block; padding: 15px 40px; background-color: #5cb85c; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">Verify Email Address</a>
</p>

<p style="font-size: 14px; color: #666666;">Or copy and paste this URL into your browser:<br>
<a href="{{link}}" style="color: #337ab7; word-break: break-all;">{{link}}</a></p>

<p style="margin-top: 20px; font-size: 14px; color: #666666;">If you didn't create an account, you can safely ignore this email.</p>
```

### Reset Password Message

Click the pencil icon next to "Reset Password" and replace with:

```html
<p>Hey {{user.friendlyName|e}},</p>

<p>We received a request to reset your password for your Blue Green Sheet account. Click the button below to choose a new password:</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{link}}" style="display: inline-block; padding: 15px 40px; background-color: #5cb85c; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">Reset Password</a>
</p>

<p style="font-size: 14px; color: #666666;">Or copy and paste this URL into your browser:<br>
<a href="{{link}}" style="color: #337ab7; word-break: break-all;">{{link}}</a></p>

<div style="background-color: #fcf8e3; border-left: 4px solid #f0ad4e; padding: 15px; margin: 20px 0; border-radius: 4px;">
    <p style="margin: 0; font-size: 14px; color: #8a6d3b;">
        <strong>Didn't request this?</strong><br>
        If you didn't ask to reset your password, you can safely ignore this email. Your password will remain unchanged.
    </p>
</div>
```

### Test Email Message

Click the pencil icon next to "Test Email" and replace with:

```html
<p><strong>Congratulations!</strong> Your email system is configured correctly and working properly.</p>

<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">
    <tr>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Test Date:</strong></td>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ now|date('F j, Y \\a\\t g:i A') }}</td>
    </tr>
    <tr>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Sent From:</strong></td>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ fromEmail }}</td>
    </tr>
    <tr>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>System Name:</strong></td>
        <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ systemName }}</td>
    </tr>
</table>

<div style="background-color: #d1e7dd; border-left: 4px solid #5cb85c; padding: 15px; margin: 20px 0; border-radius: 4px;">
    <p style="margin: 0; font-size: 14px; color: #0f5132;">
        <strong>✓ Email delivery confirmed</strong><br>
        Your email templates are being applied correctly.
    </p>
</div>
```

## Step 3: Test Your Email Setup

1. Go to **Utilities → System Messages**
2. Click **Send a Test Email**
3. Enter your email address
4. Click **Send**

You should receive a professionally styled email with:
- Green header with "Blue Green Sheet"
- Formatted content with proper spacing
- Professional footer with copyright

## Design Features

Your emails now include:
- **Green header** (#5cb85c) matching your admin notifications
- **Styled buttons** with rounded corners
- **Responsive layout** (600px max width)
- **Professional footer** with site link
- **Inline CSS** for compatibility with all email clients
- **Matching colors** from your existing admin notifications in app.php

## Troubleshooting

**Emails still look plain?**
- Make sure you saved the HTML Email Template setting in Settings → Email
- Clear your Craft CMS cache: Utilities → Clear Caches → All

**Styling not working?**
- Email clients require inline CSS (all styles in `style=""` attributes)
- Avoid external CSS files or `<style>` tags in the message body
- The wrapper template handles the main structure and styling

**Need different colors for different message types?**
- Edit the wrapper template: `templates/_emails/email-layout.html`
- You can add conditional logic based on message type if needed

## Available Variables

In your system messages, you can use:
- `{{ user.friendlyName }}` or `{{ user.fullName }}` - User's name
- `{{ user.email }}` - User's email
- `{{ link }}` - Action link (activation, verification, password reset)
- `{{ systemName }}` - Your system name
- `{{ siteName }}` - Your site name
- `{{ now }}` - Current date/time

## Matching Your Brand

These emails now match the style of your custom admin notifications in `config/app.php`:
- Same green color (#5cb85c)
- Same blue accent (#337ab7)
- Same Arial font
- Same button and table styling
- Same professional layout
