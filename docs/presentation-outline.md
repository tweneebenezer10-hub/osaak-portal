# Project presentation outline

Use these slides for an approximately 8–10 minute demonstration. Replace example descriptions with what you actually observed on your own XAMPP setup.

1. **Title and motivation** — project title, author, and the risk of frontend-only authentication.
2. **Objectives and scope** — account verification, password protection, role checks, second factors, and the deliberately local educational scope.
3. **Architecture** — browser → PHP/Apache → MySQL and SMTP; point out `public/`, `app/`, `config/`, and `database/`.
4. **Data model** — explain users, roles/user_roles, token/factor tables, and foreign keys using the ER diagram in `project-report.md`.
5. **Registration and verification demo** — register a test account, show its safe default role, receive a real email OTP, verify it, and describe expiry/resend limits.
6. **Sign-in security demo** — sign in with the mandatory email OTP, optionally enable TOTP, demonstrate a current authenticator code and a one-time recovery code, then show logout behavior.
7. **Authorization and recovery demo** — show a permitted dashboard, an administrator-only page blocked for a non-admin, then password reset using actual SMTP delivery.
8. **Security controls** — password hash plus pepper, keyed OTP hashes, CSRF, prepared SQL, escaped output, session rotation, rate limiting, and safe event logs.
9. **Test results and limitations** — show your completed test checklist with actual outcomes, screenshots if permitted, and any SMTP/runtime failures honestly.
10. **Conclusion and recommendations** — summarize learning and next steps such as automated integration tests and independent review before real deployment.

Do not display SMTP credentials, pepper/app key values, real user data, live OTPs, or recovery codes during the presentation.
