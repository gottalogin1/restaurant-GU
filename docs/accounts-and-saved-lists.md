# Saved lists and visitor accounts

Layout change 6 (see the layout comparison): a heart on every restaurant
card and a Save button on restaurant pages, a Saved page with the numbered
list and map, and a link to share the list. Saving works without an
account, and the site asks visitors to sign in so their list follows them
to every device. Code: `includes/saved/`, `assets/site/saved.js`.

## 1. How it works

| | Not signed in | Signed in |
|---|---|---|
| Where the list lives | The visitor's browser (localStorage key `gr-saved`) | User meta `gr_saved_restaurants` |
| Saved page | Sign-in panel, then the list (drawn by saved.js from `GET /wp-json/guam-restaurants/v1/saved/cards`) | The list, rendered on the server, and the account panel |
| Saving | Instant, in the browser | `POST /wp-json/guam-restaurants/v1/saved` (REST nonce) |
| At sign-in | | The browser's list is added to the account, then cleared from the browser |

- **Sign-in prompts.** After the first save and again after the fifth (once
  each per browser), a dialog offers Continue with Google, Continue with
  Facebook, Sign up with email, and Sign in. The same panel sits at the top
  of the Saved page, and the header shows Saved (with a count) and a Sign in
  button that opens the dialog.
- **Share link.** `/saved/?list=12,34,56`: the newest 100 restaurant IDs and
  nothing else. Opening it shows those restaurants with a "Save all to my
  list" button.
- **Visitor accounts ("members").** Subscribers. They never see wp-admin or
  the toolbar (wp-admin sends them to the Saved page). After signing in they
  return to the page they came from; a sign-in that would land on wp-admin or
  WooCommerce's My account lands on the Saved page instead. Owners and staff
  are not members and keep wp-admin.
- **Account page.** The Saved page: Sign out, Set or change your password
  (WordPress's lost password email, which also works for Google and Facebook
  accounts), and Delete my account (two steps; deletes the account and list,
  and LoginPress removes its Google/Facebook link). Owners and staff cannot
  delete their accounts there.
- **Privacy.** The saved list is in WordPress's personal data export and
  eraser. Nothing counts saves per restaurant, and the list is never used for
  ads or recommendations. The Saved page is `noindex`.
- **WooCommerce header icons.** WooCommerce adds its account and cart icons
  to block theme headers on its own; `Commerce\Woo_Checkout::no_header_icons`
  turns that off, since only owners buy (from My restaurant).
- **Sign-up.** Migration `2026-10-20-visitor-signup` turns on Settings >
  General > "Anyone can register" (as Subscriber) once. Turning it off later
  is kept, and also stops new Google and Facebook accounts (LoginPress checks
  the same setting).

## 2. Google and Facebook buttons (LoginPress Pro)

The buttons come from LoginPress Pro's Social Login add-on (owned). The site
calls its `[loginpress_social_login]` shortcode with `redirect_to` set to the
current page, so visitors come back where they were. No keys are stored in
this repository: they go into LoginPress's settings screen in wp-admin.
Until a provider is set up, the panels show only email sign-up and sign-in.

Before you start:

- The site must be live on its real domain with HTTPS (Facebook refuses
  plain http, and both need the final address).
- The privacy policy must be **published** (both Google and Meta ask for its
  URL). It is still a draft for counsel; this change added the accounts,
  Google/Facebook and saved list sections.
- LoginPress (free, from wordpress.org) and LoginPress Pro installed and
  active, the license key pasted in LoginPress > License, and the Social
  Login add-on turned on in LoginPress > Add-ons.
- If you use LoginPress's Hide Login add-on, set the new login address first:
  the redirect addresses below change with it.

### Google

1. Go to <https://console.cloud.google.com/> and sign in with the Google
   account that should own the app (2-Step Verification must be on).
2. Create a project, for example "Guam Restaurants".
3. Open **Google Auth Platform** (APIs & Services > OAuth consent screen),
   click **Get started**: app name (the site name), support email, audience
   **External**, contact email. Save.
4. **Branding**: app home page `https://YOUR-DOMAIN/`, privacy policy
   `https://YOUR-DOMAIN/privacy-policy/`, authorized domain `YOUR-DOMAIN`.
   A logo is optional; adding one asks Google to verify the brand first.
5. **Clients** > **Create client** > **Web application**. Under
   **Authorized redirect URIs** add
   `https://YOUR-DOMAIN/wp-login.php?lpsl_login_id=gplus_login`
   (LoginPress shows this exact address under Social Login > Google; copy it
   from there). Create.
6. Copy the **Client ID** and **Client secret** into LoginPress > Settings >
   Social Login > Google, turn Google on, save.
7. **Audience** > **Publish app** so anyone can sign in, not only test users.
   The site asks only for name, email and profile picture, which Google does
   not review.

### Facebook (also covers Instagram users)

Instagram has no sign-in for ordinary visitors; its login API is only for
business and creator accounts managing their own content, and LoginPress has
no Instagram button. Most Instagram users also have Facebook, so the
Facebook button covers them.

1. Go to <https://developers.facebook.com/apps/>, **Create app**.
2. Use case: **Authenticate and request data from users with Facebook
   Login**. Pick or create a Meta business portfolio when asked.
3. Under **Use cases** > Facebook Login > **Customize**, make sure the
   `email` and `public_profile` permissions are added.
4. Facebook Login > **Settings**: under **Valid OAuth Redirect URIs** add
   `https://YOUR-DOMAIN/wp-login.php?lpsl_login_id=facebook_check` (copy it
   from LoginPress > Social Login > Facebook > Help). Save.
5. **App settings > Basic**: App domain `YOUR-DOMAIN`, privacy policy URL
   `https://YOUR-DOMAIN/privacy-policy/`, **User data deletion**: choose
   "Data deletion instructions URL" and enter `https://YOUR-DOMAIN/saved/`
   (the page explains how to delete an account, and signed-in visitors
   delete it there), category, icon. Save.
6. Copy the **App ID** and **App secret** into LoginPress > Settings >
   Social Login > Facebook, turn Facebook on, save.
7. Switch the app to **Live** (publish). Until then only people added under
   App roles can sign in. Meta may ask you to verify the business portfolio
   and submit the app for review before it can go live; follow what the
   dashboard lists.

### LoginPress settings worth changing

- Social Login > **Button label**: `Continue with %provider%` (matches the
  site's wording). Provider order: Google first, then Facebook.
- Social Login > **Login** and **Register** forms on, so the buttons also show
  on wp-login.php.
- LoginPress > **Turnstile or reCAPTCHA** on the registration form, so
  opening sign-up does not invite bot accounts. Limit Login Attempts stays on
  (docs/security.md §4).
- Leave LoginPress's own **Login Redirects** empty for Subscribers: the site
  already sends members back to their page.

## 3. Checked

- PHPUnit (`saved-lists-test.php`): parsing, publish filter, merge order,
  cap, deleted restaurants, share link, REST permissions and caching,
  member rules, sign-in destinations, account deletion, privacy export and
  erase, page modes, the LoginPress shortcode call, the migration, the
  WooCommerce header filter.
- Playwright on the local Docker site, with LoginPress Pro 6.2.4's Social
  Login add-on loaded with placeholder keys: save without an account, prompt
  after the first save (not the third), header count, Saved page list and
  map, shared link and Save all, restaurant page Save, sign in with a
  password (three browser saves moved to the account, browser cleared, no
  toolbar), wp-admin redirect, unsave as a member, delete account. The real
  Google and Facebook round trip needs the live domain and keys.
