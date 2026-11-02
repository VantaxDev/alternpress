---
title: Privacy and cookies
status: published
---
# Privacy and cookies

This page describes technical processing on vantax.eu. We do not use profiling cookies, personalised advertising or behavioural analytics on public pages.

## Controller and contact

SERVER TAMER DI DANIELE VANTAGGIATO. For requests about personal data: [info@vantax.eu](mailto:info@vantax.eu).

## Public browsing

Public pages do not create application cookies and do not use localStorage, sessionStorage or replacement identifiers to remember browsing. The language is included in URLs; on an initial visit the server may use the browser’s Accept-Language request header or the default language. The server therefore processes technical request information even without cookies.

If the browser still sends the previous alternpress_lang cookie, the server only requests its deletion with immediate expiry. No new persistent preference is created. Once removed, the browser no longer sends that cookie.

## Administration

The restricted area uses the technical session cookie alternpress_eadbca56ea89 for authentication, protection against forged form requests (CSRF) and interface language. It is scoped to /admin/, HttpOnly, SameSite=Strict and Secure on HTTPS; the site sets no persistent expiry. Authenticated access has an eight-hour inactivity limit. This is not a profiling cookie and is not replaced by tokens in URLs.

## Technical data and security

To deliver pages the server receives connection and request data, including IP address, URL and information sent by the browser. Apache access logs record time, IP, request, status, data volume, Referer and User-Agent. IMMUTION processes request information and indicators to detect abuse and limit suspicious access; block events may include IP, host, URL, User-Agent, counters and technical reasons. We do not use these tools for advertising or visitor behavioural analytics.

The configured technical rotation keeps up to 14 Apache log archives and 30 IMMUTION event archives, rotating daily when data is present. A rotation count is not a universal deletion deadline: inactive archives and backups require separate management. These counts are not presented as retention periods prescribed by law.

## External links and rights

Links to external services such as GitHub, Mozilla or PayPal are not advertising embeds: they were not automatically loaded on the pages checked. Following a link opens a separate service with its own processing.

To exercise access, rectification, erasure, restriction and objection rights where applicable, contact the controller. A complaint may be submitted to the [Italian data protection authority](https://www.garanteprivacy.it/).
