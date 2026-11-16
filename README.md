# BIB — Borrow Instead of Buy

**Borrow what you need. Share what you have. Waste less.**

A community item-sharing and borrowing platform built as a **Software Project Design & Development Lab** project. Neighbours list the things they own but rarely use — drills, tents, projectors, ladders, books — and borrow what they need from each other instead of buying new.

Full-stack implementation: **HTML, CSS, JavaScript, PHP 8, MySQL**.

---

## 1. What's in the box

```
bib/
├── index.php                 Homepage (hero + search, categories, popular, nearby, stats, testimonials)
├── search.php                Keyword + faceted search (category, location, availability, condition, deposit, rating)
├── item_detail.php           Item page: gallery, owner reputation, availability calendar, Request/Save/Share/Contact/Report
├── register.php              Sign-up with email-verification token
├── login.php  logout.php     Session login / logout
├── verify_email.php          Consumes the verification token
├── forgot_password.php       Issues a single-use reset token
├── reset_password.php        Consumes the reset token
├── profile.php               Public profile: reputation, badges, listings, reviews
├── edit_profile.php          Photo, name, contact, location, bio
├── change_password.php       Verify current password, set a new one
├── delete_account.php        Soft-delete after password confirmation
├── list_item.php             Create a listing (multi-photo, availability, deposit, rules)
├── edit_item.php             Edit listing, manage photos and blocked dates
├── delete_item.php           Delete listing (blocked while a borrow is live)
├── my_listings.php           Owner inventory with status toggles
├── request_borrow.php        Borrower sends dates + reason + message
├── manage_request.php        Owner accepts / rejects / asks a question
├── transaction.php           Lifecycle + two-party pickup/return confirmation
├── receipt.php               Printable / downloadable transaction receipt
├── borrow_requests.php       Incoming and outgoing requests
├── transactions.php          Borrowing history, overdue detection
├── messages.php              One-to-one chat tied to a borrow conversation
├── notifications.php         In-app notification list
├── submit_review.php         Multi-criteria review → reputation score
├── wishlist.php              Saved items (AJAX toggle + list)
├── report.php                Report a listing / user / transaction, optional block
├── community.php             Discussions, posts, comments, likes
├── dashboard.php             User overview: quick actions, stats, upcoming returns
├── impact.php                Sustainability impact dashboard
├── admin/
│   ├── index.php             Platform metrics + moderation overview
│   ├── users.php             Verify / suspend / ban / reactivate
│   ├── items.php             Approve / reject / remove / feature listings
│   ├── reports.php           Triage reports, escalate to dispute
│   └── disputes.php          Resolve disputes, adjust deposits
├── includes/
│   ├── config.php            DB credentials + app constants  ← EDIT THIS
│   ├── functions.php         PDO, sessions, auth, CSRF, reputation, notifications
│   ├── header.php            Shared page shell (nav, theme toggle, flash)
│   ├── footer.php            Shared footer
│   └── sidebar.php           Dashboard navigation
├── assets/
│   ├── css/style.css         Design system (light/dark, responsive)
│   ├── css/app.css           Component styles for the PHP views
│   └── js/app.js             Theme toggle, nav, modals, AJAX wishlist
├── sql/
│   └── bib_schema.sql        Full schema + seed data (import this)
└── uploads/                  User-uploaded item photos and avatars
```

---

## 2. Setup (XAMPP)

### 2.1 Requirements
- XAMPP with **PHP 8.0+** and **MySQL/MariaDB**
- Extensions: `pdo_mysql` (required). `mbstring` is optional — the code falls back to a UTF-8-safe polyfill if it is disabled.

### 2.2 Install
1. **Copy the project** into the XAMPP web root:
   ```
   C:\xampp\htdocs\bib\        (Windows)
   /opt/lampp/htdocs/bib/      (Linux)
   ```
2. **Start Apache and MySQL** from the XAMPP Control Panel.
3. **Import the database** — either:
   - *phpMyAdmin:* open <http://localhost/phpmyadmin> → **Import** → choose `sql/bib_schema.sql` → **Go**.
     The file creates the `bib_db` database itself, so you do not need to create one first.
   - *Command line:*
     ```
     mysql -u root -p --default-character-set=utf8mb4 < sql/bib_schema.sql
     ```
     The `--default-character-set=utf8mb4` flag matters: the seed data contains emoji category icons.
4. **Configure credentials** — open `includes/config.php` and set your MySQL user/password:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'bib_db');
   define('DB_USER', 'root');
   define('DB_PASS', '');          // XAMPP default is an empty password
   ```
   Also confirm `APP_URL` matches your folder name:
   ```php
   define('APP_URL', 'http://localhost/bib');
   ```
5. **Make uploads writable** (Linux/macOS only):
   ```
   chmod -R 775 uploads/
   ```
6. **Open the site:** <http://localhost/bib/>

### 2.3 Demo accounts

All three accounts use the password **`password123`**.

| Role | Email | What to look at |
|------|-------|-----------------|
| **Borrower** | `rafi@bib.demo` | Dashboard, requests sent, wishlist, reviews |
| **Lender** | `arif@bib.demo` | My listings, incoming requests, accept/reject, handover |
| **Admin** | `admin@bib.demo` | `/admin/` — metrics, user & item moderation, reports, disputes |

Other seeded users: `nusrat@`, `tanvir@`, `mahin@`, `sadia@`, `farhana@`, `imran@`, `shakib@` (all `@bib.demo`, same password).

> **Email delivery:** this is a lab project, so outbound mail is not configured. Verification and reset links are written to the page (and the token is stored in the `users` table) instead of being emailed. To send real mail, wire `mail()` or PHPMailer into `send_verification_email()` / `send_reset_email()` in `includes/functions.php`.

---

## 3. Database

**20 entities** (plus two reporting views):

`users`, `profiles`, `categories`, `items`, `item_photos`, `availability`, `borrow_requests`, `transactions`, `returns`, `reviews`, `conversations`, `messages`, `notifications`, `wishlist`, `reports`, `blocks`, `disputes`, `deposits`, `badges`, `user_badges`, `community_posts`, `post_comments`, `post_likes`

Views: `v_item_cards` (listing cards with owner + rating), `v_platform_stats` (admin metrics).

Seed data: 10 users, 12 items across 8 categories, 6 borrow requests, 8 transactions in every lifecycle state, returns, reviews, deposits, notifications, badges, wishlist rows and community posts — so every page renders meaningful content on first load.

### Reputation score
`profiles.reputation_score` is a 0–100 score recomputed after each review from four weighted signals:

| Signal | Weight |
|--------|--------|
| Average overall rating | 50% |
| Completed borrows (volume) | 20% |
| Timely returns | 20% |
| Response speed | 10% |

New accounts start at 70. See `recompute_reputation()` in `includes/functions.php`.

---

## 4. Feature → SRS / ER / DFD mapping

| SRS module | Implementation | ER entities | DFD |
|------------|----------------|-------------|-----|
| Authentication & authorisation | `register.php`, `login.php`, `logout.php`, `verify_email.php`, `forgot_password.php`, `reset_password.php`; `password_hash()`/`password_verify()`, session regeneration, CSRF tokens, `require_login()` / `require_admin()` | `users` | P1 Auth → D1 Users |
| Profile & reputation | `profile.php`, `edit_profile.php`, `change_password.php`, `delete_account.php` | `profiles`, `badges`, `user_badges`, `reviews` | P2 Profile → D2 Profiles |
| Item management | `list_item.php`, `edit_item.php`, `delete_item.php`, `my_listings.php` | `items`, `item_photos`, `categories`, `availability` | P3 Listings → D3 Items |
| Search & discovery | `search.php`, `index.php`, `item_detail.php` | `items`, `categories`, `profiles` | P4 Search → D3 Items |
| Borrow request | `request_borrow.php`, `manage_request.php`, `borrow_requests.php` | `borrow_requests`, `conversations` | P5 Request → D4 Requests |
| Transaction lifecycle | `transaction.php`, `transactions.php`, `receipt.php` | `transactions`, `returns`, `deposits` | P6 Transaction → D5 Transactions |
| Messaging | `messages.php` | `conversations`, `messages` | P7 Messaging → D6 Messages |
| Notifications | `notifications.php`; `notify()` helper | `notifications` | P8 Notify → D7 Notifications |
| Ratings & reputation | `submit_review.php`; `recompute_reputation()` | `reviews`, `profiles` | P9 Review → D8 Reviews |
| Trust & safety | `report.php`, `admin/reports.php`, `admin/disputes.php` | `reports`, `blocks`, `disputes` | P10 Moderation → D9 Reports |
| Community | `community.php` | `community_posts`, `post_comments`, `post_likes` | P11 Community → D10 Posts |
| Admin | `admin/*.php` | all | P12 Admin → D1–D10 |
| Sustainability impact | `impact.php` | `transactions`, `items` | P13 Impact → D5 Transactions |

**Transaction state machine** (enforced in `transaction.php`):

```
Requested → Accepted → Pickup → Borrowed → Return Requested → Returned → Completed
                                                                  ↘ Disputed
```

Pickup and return each require **both** parties to confirm; the deposit moves `pending → held → returned` (or `deducted` on a resolved dispute).

---

## 5. Security notes

- Passwords hashed with `password_hash()` (bcrypt); never stored or logged in plain text.
- All queries use **PDO prepared statements** — no string-concatenated SQL.
- Every state-changing form carries a **CSRF token** verified with `hash_equals()`.
- All output escaped through `e()` (`htmlspecialchars`, `ENT_QUOTES`).
- Session ID regenerated on login; role checks on every protected page.
- Uploads validated by extension and MIME type, stored outside the code path with generated filenames.

---

## 6. Static design preview

A server-free HTML/CSS/JS preview of the key screens (homepage, search results, item details, dashboard, admin panel, login, receipt) ships alongside this project so the design can be reviewed in a browser without PHP or MySQL. It shares the same design system (`assets/css/style.css`) and brand identity.

---

*Lab project — not production-hardened. Add HTTPS, real email delivery, rate limiting and backups before any real deployment.*
