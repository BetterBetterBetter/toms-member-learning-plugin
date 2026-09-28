# Testing a member's Library access

Administrators can inspect the Library with the exact WordPress identity and
live MemberPress decisions of a member. This is an integration with Web357's
**Login as User** plugin; it does not introduce a second impersonation system
or reproduce access rules in the Library.

## Operator workflow

1. Open **MemberPress → Members** and choose **View access** in the
   **Library access** column. The same action is available as **View Library
   access** on the normal WordPress Users screen.
2. WordPress uses Login as User's nonce-protected switch, then immediately
   starts the existing Library OAuth flow as that member.
3. The Library opens with **My Content** selected and displays a fixed
   **Access test** banner naming the active account.
4. Inspect catalogue cards and protected routes. Access is still decided live
   by MemberPress. Watching content, changing progress, or editing notes writes
   to the member's real account.
5. Choose **Finish access test** in the banner. WordPress validates the
   one-time test, switches back through Login as User, and runs the normal
   Library OAuth flow as the administrator. The banner is cleared and
   **All Content** is restored.

Do not use the normal Library **Sign out** action to finish a test. Normal
sign-out deliberately logs out WordPress too, which removes Login as User's
route back to the original administrator.

## Security and failure behavior

- Actions are shown only when Library authentication is configured, Login as
  User is active, the actor has `manage_options`, and Login as User grants the
  actor its existing `login_as_user` capability for the target.
- Both bridge actions are authenticated `admin-post.php` actions. There are no
  anonymous variants.
- The member-side bridge verifies Login as User's original-user cookies and
  binds a cryptographically random token to the administrator and member.
- WordPress stores only a SHA-256-derived transient key. The record expires
  after eight hours and is deleted before the switch-back redirect.
- Finishing requires the same member session, the same authenticated original
  administrator, and the one-time token. Any mismatch fails with a generic 403.
- All cross-application identity changes pass through the existing PKCE OAuth
  flow. The Library never receives WordPress cookies or an impersonation grant.
- The browser marker is tab-scoped in `sessionStorage`. The catalogue scope
  preference remains the existing local-storage preference.

The integration is optional and silent when Login as User is inactive.

## Release order

Deploy the Library support first; it is inert without an access-test marker.
Then release the WordPress plugin integration. This ensures every newly
started test has the visible warning and **Finish access test** control.

Contract checks:

- WordPress: `tests/library-access-testing-contract.php`
- Library: `lib/auth/access-testing/access-test.test.ts` and
  `lib/catalogue/personalization/scope.test.ts`
