# Legal and Policy Summary

**Status:** Draft, 2026-10-09. **Not legal advice.** This is a non-lawyer summary written to help the owner prepare for a conversation with a Guam-licensed attorney. Every item marked **Ask counsel** needs a lawyer's confirmation before launch. Laws, penalty amounts, and agency guidance change. Re-check each primary source link before relying on it.

**Scope.** A Guam restaurant directory built on WordPress that:

- lists restaurants by village, mall, and cuisine;
- shows third-party ratings and reviews (Google, Yelp, and others, through their APIs);
- shows awards history (for example, Pika's Best) and first-party highlights;
- may accept first-party visitor reviews or tips later;
- sells advertising and sponsored placements to restaurants;
- uses analytics, web push (Gravitec), forms, memberships (WishList Member), and possibly bookings (Amelia).

API terms for Google, Yelp, and other data sources are covered in `docs/data-sources.md`, not here.

---

## Contents

1. [Quick summary](#1-quick-summary)
2. [FTC rules on endorsements and consumer reviews](#2-ftc-rules-on-endorsements-and-consumer-reviews)
3. [Advertising disclosure](#3-advertising-disclosure)
4. [Guam consumer protection law](#4-guam-consumer-protection-law)
5. [Privacy and visitor tracking](#5-privacy-and-visitor-tracking)
6. [Removal and correction requests from restaurant owners](#6-removal-and-correction-requests-from-restaurant-owners)
7. [Other items to raise with counsel](#7-other-items-to-raise-with-counsel)
8. [Questions for the Guam attorney](#8-questions-for-the-guam-attorney)
9. [Draft editorial policy](#9-draft-editorial-policy)
10. [Sources](#10-sources)

---

## 1. Quick summary

| Risk area | What matters most for this site | Severity if ignored |
|---|---|---|
| Ads mistaken for editorial content | Every paid placement is labeled at the top, in plain words, on every device. Paid items never sit in organic rankings without a label. | High. FTC Act Section 5 and Guam 5 GCA § 32201. |
| Ads influencing ratings, awards, or rankings | Keep a hard wall between sales and editorial. Publish the ranking and award methods. | High. Deceptive practice and loss of trust. |
| Review handling | Never edit, filter, or suppress reviews based on sentiment. Never pay for or solicit only positive reviews. Disclose insider or advertiser connections. | High. 16 CFR Part 465 carries civil penalties above $53,000 per violation. |
| Owner removal requests | Correct facts quickly. Do not remove truthful listings or negative reviews because an owner or advertiser asks. | Medium. Part 465 and Section 5 risk if handled badly. |
| Visitor tracking | Publish a privacy policy. Get consent for non-essential cookies from EU and UK visitors. Avoid pixels on video pages and session-replay tools unless counsel approves. | Medium. CalOPPA, GDPR, and wiretap and VPPA lawsuits. |
| Data breach | Do not store card numbers, SSNs, or IDs. Use a payment processor. | Low if card data is never stored. Guam 9 GCA Ch. 48. |

---

## 2. FTC rules on endorsements and consumer reviews

Three federal sources govern how a directory collects, shows, and uses reviews. They apply to a Guam business because the FTC Act covers commerce in US territories.

### 2.1 FTC Act Section 5

[15 U.S.C. § 45](https://www.law.cornell.edu/uscode/text/15/45) bans "unfair or deceptive acts or practices." Every FTC rule and guide below applies this general ban to a specific situation. A practice can violate Section 5 even when no specific rule covers it.

### 2.2 Endorsement Guides (16 CFR Part 255, revised July 2023)

Primary source: [16 CFR Part 255 on eCFR](https://www.ecfr.gov/current/title-16/chapter-I/subchapter-B/part-255). FTC plain-language FAQ: [FTC's Endorsement Guides: What People Are Asking](https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking).

Points that apply to this site:

- **Reviews count as endorsements when used in advertising.** If the site quotes a review inside an ad, a newsletter promotion, or a sponsored placement, the review becomes an endorsement, and the FTC's truthfulness rules apply.
- **Do not distort reviews (§ 255.2(d)).** An advertiser should not procure, suppress, boost, organize, edit, or report reviews in a way that distorts what consumers think. That applies to the site when it promotes advertisers, and to restaurants that advertise on it.
- **Disclose material connections (§ 255.5).** A material connection is any relationship that could change how much weight a reader gives an endorsement and that the reader would not expect. Examples are payment, free meals, discounts, family ties, or employment. If the site's staff praise an advertiser, accept a free meal before writing a highlight, or feature a restaurant owned by someone connected to the site, the connection must be disclosed clearly and conspicuously, close to the praise.
- **Intermediaries can be liable (§ 255.1).** Publishers, agencies, and review brokers that help spread deceptive endorsements they know or should know about can be liable. Good-faith efforts are not a safe harbor.

### 2.3 Rule on the Use of Consumer Reviews and Testimonials (16 CFR Part 465, effective October 21, 2024)

Primary source: [16 CFR Part 465 on eCFR](https://www.ecfr.gov/current/title-16/chapter-I/subchapter-D/part-465). Final rule notice: [89 FR 68077 (Aug. 22, 2024)](https://www.ftc.gov/system/files/ftc_gov/pdf/r311003consumerreviewstestimonialsfinalrulefrn.pdf). FTC Q&A: [Consumer Reviews and Testimonials Rule: Questions and Answers](https://www.ftc.gov/business-guidance/resources/consumer-reviews-testimonials-rule-questions-answers).

Unlike the Guides, this is a trade regulation rule, so the FTC can seek civil penalties for each knowing violation. The penalty is adjusted for inflation each year. A 2026 law-firm summary lists it at up to $53,088 per violation and reports that the FTC sent its first public warning letters under the rule in late 2025 ([Benesch, April 2026](https://www.beneschlaw.com/insight/five-stars-zero-tolerance-ftc-turns-up-enforcement-under-consumer-review-rule/pdf/)).

| Section | What it prohibits | How it applies here |
|---|---|---|
| § 465.2 | Writing, buying, or spreading fake reviews or testimonials, including ones from people who never visited | Never write reviews for restaurants. Never accept reviews that ad clients supply. |
| § 465.4 | Offering anything of value for a review that expresses a particular sentiment | Contests or rewards for reviews must not depend on the review being positive. Disclosing the reward does not fix a sentiment-based incentive. |
| § 465.5 | Insider reviews (officers, managers, employees, agents, relatives) without a clear disclosure | If first-party reviews open, ask reviewers whether they work for or are related to the restaurant, and label or reject those reviews. |
| § 465.6 | Claiming a site controlled by a business is an independent source of reviews or opinions | If an owner of the site also owns or co-owns a restaurant, disclose that on every page where that restaurant is rated or ranked. Do not describe the site as "independent" if that is not true. |
| § 465.7 | Using groundless legal threats, intimidation, or false accusations to remove reviews, and claiming displayed reviews are all or most reviews when negative ones are suppressed | Never remove negative reviews because an owner or advertiser asks. Never say "all reviews" if any are filtered. |
| § 465.8 | Buying or selling fake social-media indicators (bots, fake followers) | Never buy followers or engagement for the site's social accounts. |

**Hosting exemption.** The FTC Q&A says a business that only hosts consumer reviews is not liable under the rule for fake reviews it did not write or buy, and it has no duty to screen every review. That exemption ends where the site uses reviews in its own marketing, because then they become testimonials.

**Sorting and moderation.** The FTC Q&A says that sorting reviews by helpfulness or star rating is not suppression under the rule. A site may refuse reviews that are patently false (for example, about a different business), and it may exclude topics such as off-topic content, as long as the policy applies equally to positive and negative reviews. Burying negative reviews so that readers cannot find them can still violate Section 5.

### 2.4 FTC guidance for review platforms

Source: [Featuring Online Customer Reviews: A Guide for Platforms (Dec. 2021)](https://www.ftc.gov/business-guidance/resources/featuring-online-customer-reviews-guide-platforms). These are staff recommendations, not a rule, but they show how the FTC reads Section 5 for review sites:

- Do not ask only customers likely to leave positive reviews.
- Apply the same checks to positive and negative reviews.
- Do not edit reviews in a way that changes their meaning.
- Publish negative reviews, not just positive ones.
- Explain how reviews are collected and how overall ratings are calculated.
- Investigate reported reviews and act on them.

### 2.5 Consumer Review Fairness Act

Source: [15 U.S.C. § 45b](https://www.law.cornell.edu/uscode/text/15/45b). FTC summary: [Consumer Review Fairness Act: What Businesses Need to Know](https://www.ftc.gov/business-guidance/resources/consumer-review-fairness-act-what-businesses-need-know).

Form contracts with consumers may not ban or penalize honest reviews, or claim the copyright in them. For this site, that means:

- The site's own Terms of Use must not stop visitors from criticizing the site.
- If a restaurant's own customer contract has a gag clause, that is the restaurant's problem, but the site should not help enforce it (for example, by removing a review because the restaurant cites such a clause).

### 2.6 What this means for third-party ratings shown through APIs

The project rule is to show third-party review content exactly as the API returns it, with attribution, and never filter or alter it. That rule lines up with § 255.2(d) and § 465.7:

- Do not pick only the highest-rated Google or Yelp reviews to show on a listing unless the platform's API decides which reviews are returned.
- Label every rating with its source and, where the API gives it, the review count and date.
- If the site computes its own combined score, publish the formula (see the editorial policy, section 9).

---

## 3. Advertising disclosure

### 3.1 Native advertising and sponsored listings

Primary sources:

- [Enforcement Policy Statement on Deceptively Formatted Advertisements (Dec. 2015)](https://www.ftc.gov/legal-library/browse/commission-enforcement-policy-statement-deceptively-formatted-advertisements)
- [Native Advertising: A Guide for Businesses](https://www.ftc.gov/business-guidance/resources/native-advertising-guide-businesses)
- [.com Disclosures: How to Make Effective Disclosures in Digital Advertising (2013)](https://www.ftc.gov/business-guidance/resources/com-disclosures-how-make-effective-disclosures-digital-advertising)

An ad that looks like a listing, article, or "best of" entry is deceptive unless it is clearly labeled. The FTC's guidance for native ads says:

- **Wording.** "Ad," "Advertisement," "Paid Advertisement," and "Sponsored Advertising Content" are understood. The FTC calls out "Promoted," "Promoted Stories," "Presented by," "Brought to you by," and "Sponsored by" as ambiguous, because they can suggest the sponsor paid for content without shaping it.
- **Placement.** Put the label in front of or above the headline, or on the image if the image is the eye-catching element. Labels to the right or below are easy to miss.
- **Groups.** When one label covers several items, use borders or shading so readers can tell which items are paid.
- **Consistency.** Use the same label everywhere on the site and keep it on click-through pages and shared links.
- **Prominence.** Use a readable font, strong contrast, and a label that shows on mobile.

**Recommendation for this site.** The project rule requires the label "Sponsored." On its own, the FTC treats "Sponsored by" as ambiguous, and treats "Sponsored Advertising Content" as clear. To satisfy both, use **"Sponsored · Ad"** as the badge on sponsored cards and placements, and "Sponsored Advertising Content" as the heading on any paid article. **Ask counsel** whether "Sponsored" alone is enough for a listing card.

### 3.2 Paid placement in directory search and filters

The FTC wrote to search engines, including 17 shopping, travel, and local-business search sites, telling them to keep paid results clearly separate from natural results ([FTC press release, June 25, 2013](https://www.ftc.gov/news-events/news/press-releases/2013/06/ftc-consumer-protection-staff-updates-agencys-guidance-search-engine-industry-need-distinguish)). This directory's village, mall, and cuisine filters are a local-business search engine. So:

- Sponsored results in a filtered list appear in a labeled slot (for example, the top of the list) and are visually set apart.
- The organic list below is ordered by the published method only. A sponsored restaurant also appears in its normal organic position, unboosted.
- "Featured," "Top pick," and "Recommended" are never used for paid placements, because readers take them as editorial judgments.

### 3.3 Other paid relationships to disclose

- **Affiliate links** (for example, delivery or booking links that pay a commission). Disclose near the link, for example "We may earn a commission if you order through this link."
- **Free meals or hosted visits** before staff write a highlight. Disclose on the highlight.
- **Advertiser status on award and ranking pages.** Ads must never affect awards or rankings, but readers may still care. The editorial policy (section 9) lists advertiser status on award pages the site itself issues.
- **Owner-submitted content.** Text or photos supplied by a restaurant are labeled "Provided by the restaurant."

### 3.4 Email and push

- Commercial email (newsletters with ads, promotions) must follow [CAN-SPAM](https://www.ftc.gov/business-guidance/resources/can-spam-act-compliance-guide-business): accurate headers and subject lines, identification as an ad where applicable, a physical postal address, and a working unsubscribe that is honored within 10 business days.
- Web push (Gravitec) uses the browser's own opt-in. Sponsored push messages should carry the same "Sponsored · Ad" label.

---

## 4. Guam consumer protection law

Source: [5 GCA Chapter 32, Trade Practices and Consumer Protection (Justia, 2024 edition)](https://statecodesfiles.justia.com/guam/2024/title-5/division-3/chapter-32/chapter-32.pdf). Index: [Justia, 5 GCA Division 3](https://law.justia.com/codes/guam/title-5/division-3/).

- **§ 32201** makes deceptive trade practices unlawful. Unlabeled ads, pay-to-rank schemes, or misleading claims about independence would likely fall within its scope.
- **§ 32111 and § 32112** let consumers sue. Relief includes actual and punitive damages, and up to treble damages for knowing violations.
- **§ 32127** lets the Guam Attorney General seek civil penalties of up to $5,000 per violation, and up to $10,000 per violation of an injunction.

**Ask counsel:** whether Guam courts look to FTC guidance when applying Chapter 32, and whether advertising contracts with restaurants (business-to-business) raise any separate Chapter 32 issues.

---

## 5. Privacy and visitor tracking

Guam has no comprehensive consumer privacy law comparable to California's or the EU's, as far as this research found. **Ask counsel** to confirm, because bills in this area move quickly. The site's audience includes tourists from Japan, Korea, and elsewhere, and US visitors from many states, so several outside laws may reach it.

### 5.1 Laws that may apply

| Law | When it applies | What it requires | Source |
|---|---|---|---|
| FTC Act Section 5 | Always | The privacy policy must be accurate. Collecting or sharing data in ways the policy does not describe is deceptive. | [15 U.S.C. § 45](https://www.law.cornell.edu/uscode/text/15/45) |
| Guam breach notice law, 9 GCA Ch. 48 | The site holds a Guam resident's name with an SSN, driver's license or Guam ID number, or a financial account or card number with its access code | Notify affected Guam residents without unreasonable delay after a breach. The Attorney General can seek up to $150,000 per breach. | [9 GCA Ch. 48 (Justia)](https://law.justia.com/codes/guam/title-9/chapter-48/) |
| California Online Privacy Protection Act (CalOPPA) | The site collects personal information (such as name or email) from California residents | Post a conspicuous privacy policy. Say how the site responds to Do Not Track signals and whether third parties track visitors across sites. | [Cal. Bus. & Prof. Code §§ 22575–22579](https://leginfo.legislature.ca.gov/faces/codes_displayText.xhtml?lawCode=BPC&division=8.&chapter=22.&part=&article=) |
| Other US state privacy laws (California CCPA and similar) | Usually only above revenue or volume thresholds (for example, data on 100,000 or more residents of one state) | Opt-out of "sale" or "sharing" for targeted ads. Honor Global Privacy Control. | Ask counsel whether any threshold is likely to be met. |
| COPPA | The site is directed at children under 13, or knows it is collecting data from a child under 13 | Parental consent before collecting data. A general-audience food directory is not child-directed, but forms should not knowingly collect from children. The FTC amended the rule in 2025. | [16 CFR Part 312](https://www.ecfr.gov/current/title-16/chapter-I/subchapter-C/part-312), [FTC COPPA page](https://www.ftc.gov/legal-library/browse/rules/childrens-online-privacy-protection-rule-coppa) |
| EU and UK GDPR | The site monitors the behavior of people in the EU or UK (analytics, ad tracking), or offers services to them | A lawful basis for processing, prior consent for non-essential cookies under ePrivacy rules, and rights to access and deletion | [GDPR Article 3](https://gdpr-info.eu/art-3-gdpr/) |
| Japan APPI and Korea PIPA | Possibly, when the site targets tourists from those countries | Ask counsel. Both laws can reach foreign businesses that handle their residents' data. | Ask counsel. |
| Video Privacy Protection Act | The site shows video (Featured Video Plus) and shares viewing data plus an identifier with a third party, such as through an ad pixel | Recent class actions target sites that run ad pixels on video pages. | [18 U.S.C. § 2710](https://www.law.cornell.edu/uscode/text/18/2710) |
| State wiretap laws (for example, California's CIPA) | Session-replay tools, chat widgets, or pixels that capture what visitors type or click | Recent class actions claim these tools intercept communications without consent. | Ask counsel. |

### 5.2 Practical baseline (for counsel to confirm)

1. **Privacy policy** linked in the footer of every page. It should list each tracking tool by name (for example, Google Analytics, Gravitec, any ad network), what it collects, why, how long data is kept, how to opt out, and how the site responds to Do Not Track and Global Privacy Control. Google Analytics' own terms require the site to disclose its use.
2. **Cookie consent banner** that blocks non-essential cookies until consent, at least for EU and UK visitors. Many sites apply it to all visitors because it is simpler. Pick a well-maintained consent plugin rather than custom code.
3. **No ad or social pixels on pages with video**, and no session-replay tools, unless counsel approves.
4. **Data minimization.** Collect only what each form needs. Do not ask for birth dates, ID numbers, or home addresses.
5. **Payments through a processor** (for example, Stripe or PayPal through Amelia or WishList Member). The site never stores card numbers, which keeps it outside most of 9 GCA Ch. 48 and limits PCI scope.
6. **Retention.** Delete form submissions, contact requests, and removal-request records on a schedule (for example, 24 months), except where needed for a dispute.
7. **Email and push opt-in** recorded with date and source. Unsubscribes honored promptly.
8. **Breach plan.** A one-page plan that names who decides, who notifies, and how. Keep it with the hosting and security plugin details.

---

## 6. Removal and correction requests from restaurant owners

### 6.1 Legal background

- **Section 230** ([47 U.S.C. § 230](https://www.law.cornell.edu/uscode/text/47/230)) generally protects the site from liability for content that someone else wrote, such as visitor reviews and API-supplied reviews. It does not protect content the site writes itself (highlights, rankings, award write-ups, descriptions). It also does not apply to federal intellectual-property claims. Section 230 applies in Guam as federal law.
- **Copyright takedowns.** To use the DMCA safe harbor for content users upload ([17 U.S.C. § 512](https://www.law.cornell.edu/uscode/text/17/512)), the site must register a designated agent with the [US Copyright Office DMCA directory](https://www.copyright.gov/dmca-directory/), post the agent's contact details, and remove infringing material promptly on a valid notice. Registration must be renewed every three years ([37 CFR § 201.38](https://www.law.cornell.edu/cfr/text/37/201.38)).
- **Trademarks.** Using a restaurant's name to identify it in a factual listing is generally allowed. Using its logo, or wording that implies the restaurant endorses or partners with the site, needs permission.
- **Defamation and false statements.** Statements of fact the site writes itself must be accurate. Correct errors quickly.
- **Part 465 and Section 5** (section 2 above) mean the site must not remove negative reviews because an owner, including an advertiser, asks.
- **Truthful facts.** There is generally no US legal right for a business to have a truthful listing of its public facts (name, address, hours, cuisine) removed. **Ask counsel** to confirm for Guam.

### 6.2 Recommended handling (for counsel to confirm)

| Request | Response |
|---|---|
| Wrong facts (hours, phone, address, menu link, closed or moved) | Verify and correct; reply within 7 days (14 days for owner change or removal requests; editorial-policy.md). Log the change with date and source, since the site plans change tracking. |
| Restaurant closed permanently | Mark "Closed" with the date, rather than deleting, so award history stays accurate. |
| Remove the whole listing | Explain the site lists public facts about operating restaurants. Offer to correct or limit details. Remove personal information about individuals, such as a home-based caterer's home address or a personal phone number. |
| Remove or change a third-party review (Google, Yelp, and others) | Decline. The site shows API content unaltered. Point the owner to that platform's reporting process. |
| Remove a first-party visitor review | Check it against the published review rules (off-topic, wrong business, hate speech, personal information, conflict of interest, plainly fake). Remove it only if it breaks a rule that applies equally to positive and negative reviews. Never remove a review because it is negative. |
| Respond to a review | Allow one public owner reply per review, labeled "Response from the owner." |
| Remove a photo or text the owner says is theirs | Treat as a copyright notice. Follow the DMCA process if the item came from a user, and remove it promptly if the site uploaded it without a license. |
| Remove or change the site's own editorial text or an award page | Correct factual errors. Do not change opinions or award results because an owner, advertiser, or prospect asks. |
| Requests tied to advertising ("I'll buy an ad if you remove this") | Decline in writing and record it. Sales staff must never offer to remove or change reviews, ratings, rankings, or awards. |
| Legal threats | Do not ignore them. Acknowledge receipt, preserve records, and forward them to counsel. |

Publish a short **Corrections and Removals** page that explains these rules and gives one contact form. Keep a private log of each request: date, requester, what was asked, decision, and reason.

---

## 7. Other items to raise with counsel

- **Business structure and licensing.** Guam business license from the Department of Revenue and Taxation, and how gross receipts tax applies to advertising revenue.
- **Advertising contract.** A standard insertion order or terms for restaurant advertisers. It should state that advertising does not buy or affect ratings, reviews, awards, or rankings; that the site may reject or label ads; that the advertiser is responsible for its own ad claims; and the payment, cancellation, and refund terms.
- **Ad content review.** The site can share responsibility for ads it designs or presents. Decide how to check advertiser claims such as "Guam's best," "award-winning," or health claims.
- **Award names and marks.** Showing award facts (winner, category, year) with a source link is factual. Using an awarding body's logo or badge needs permission. See `docs/awards-research.md`.
- **Terms of Use.** Content license from users who submit reviews or photos, review rules, prohibited uses, disclaimers, limitation of liability, governing law (Guam), and a dispute process. No clause may restrict honest reviews of the site (Consumer Review Fairness Act).
- **Website accessibility.** ADA Title III lawsuits over website accessibility are common in the US. Target WCAG 2.2 AA.
- **Insurance.** Media liability or errors-and-omissions coverage for defamation, copyright, and privacy claims.

---

## 8. Questions for the Guam attorney

1. Is "Sponsored" alone enough as a label on listing cards and filtered results, or should it be "Sponsored · Ad"?
2. Do Guam courts apply FTC guidance when interpreting 5 GCA § 32201? Are there Guam cases about online advertising or reviews?
3. Does any Guam privacy law, regulation, or pending bill beyond 9 GCA Ch. 48 apply to website analytics and cookies?
4. Given visitors from Japan, Korea, the EU, and US states, which outside privacy laws should the site plan for, and is a site-wide consent banner advisable?
5. Can a restaurant owner force removal of a truthful listing in Guam? What risk comes from listing home-based or unlicensed food businesses?
6. Should the site register a DMCA agent before launch, even if users cannot upload content at first?
7. Should the site disclose a restaurant's advertiser status on award and ranking pages the site itself publishes?
8. What should the advertiser contract and the Terms of Use include? Is there a Guam form the attorney recommends?
9. What are the business license and gross receipts tax obligations for advertising revenue?
10. If an owner of this site has an interest in any restaurant, what disclosure does 16 CFR § 465.6 require?

---

## 9. Draft editorial policy

> Draft for the owner and counsel to review. Intended for publication at `/editorial-policy/`, linked from the footer and from every rating, ranking, award, and sponsored placement.

### Our Editorial Independence Policy

**Effective date:** [to be set at launch]

[Site name] helps people find places to eat on Guam. We earn money by selling advertising to restaurants. This policy explains how we keep that advertising separate from everything we tell you about where to eat.

#### Our promise

**Advertising never influences our ratings, awards, or rankings.** No restaurant can pay to receive a higher rating, a better position in our lists or search results, an award, a "best of" mention, or the removal of a review. Restaurants that advertise with us are treated exactly the same as restaurants that do not.

#### How we keep advertising and editorial separate

1. **Separate decisions.** The people who sell advertising do not decide or change ratings, rankings, awards, highlights, or review moderation. People who make editorial decisions are not paid commissions on advertising sales.
2. **No promises from sales.** Our advertising contracts state that buying an ad does not affect editorial content. Anyone who offers otherwise on our behalf is breaking this policy, and we want to know (see "Report a concern" below).
3. **Written methods.** We publish how we order lists and search results, how we calculate any combined score, and how we choose award winners. We do not change a method to help or hurt a specific restaurant.
4. **Records.** We keep a record of every change to a rating, ranking method, or award result, with the date and reason.

#### How we label advertising

- Paid placements carry the label **"Sponsored · Ad"** above the headline or on the image, on every device.
- Sponsored placements appear in clearly marked spaces, set apart from regular results with a border or shading.
- A restaurant that buys a sponsored placement also appears in its normal position in our regular results, with no boost.
- We never use words like "Featured," "Top pick," or "Recommended" for paid placements.
- Content supplied by a restaurant is labeled **"Provided by the restaurant."**
- Links that may earn us a commission are labeled near the link.
- On award and ranking pages we publish, we note which restaurants currently advertise with us, so you can judge for yourself.

#### Ratings and reviews from other sites

- We show ratings and reviews from services such as Google and Yelp as their services provide them, with the source named next to each one. We do not edit, filter, reorder, or select those reviews.
- If an owner disagrees with a review on another site, we direct them to that site's own reporting process.

#### Reviews on our site

If we accept reviews from visitors:

- We publish positive and negative reviews alike.
- We remove a review only when it breaks our published review rules, such as being about a different business, containing hate speech or personal information, being written by someone connected to the restaurant without saying so, or showing clear signs of being fake. We apply those rules the same way to positive and negative reviews.
- We never remove or hide a review because a restaurant, advertiser, or prospective advertiser asks.
- We never pay for reviews or reward reviews based on what they say.
- Restaurant owners may post one public response to each review, labeled as a response from the owner.

#### Awards

- Third-party awards (for example, from a local publication) are shown as reported by the organization that gave them, with the year, category, and a link to the source. They are that organization's decision, not ours.
- Any awards we give ourselves follow a published method and timeline. Advertising status is never a factor, and we note which winners advertise with us.

#### Gifts, meals, and conflicts of interest

- Our staff and contributors pay for their own meals when writing highlights, unless we say otherwise on that page.
- If someone who writes for us, or owns part of our business, has a financial or family connection to a restaurant, we say so on that restaurant's page, and that person does not rate, rank, or write about it.

#### Corrections

Owners and readers can report factual errors through our [Corrections and Removals](/corrections/) page. We fix factual errors promptly and note significant corrections. We do not change opinions, ratings, rankings, or award results on request.

#### Report a concern

If you believe anyone has offered or asked for special treatment in exchange for advertising, please contact [editor email]. We investigate every report.

---

## 10. Sources

Retrieved 2026-10-09. FTC, eCFR, and Guam code pages were read for the points cited; statute links to Cornell LII and the California code page are given for reference.

**Federal statutes and rules**

- FTC Act Section 5, 15 U.S.C. § 45: <https://www.law.cornell.edu/uscode/text/15/45>
- Endorsement Guides, 16 CFR Part 255: <https://www.ecfr.gov/current/title-16/chapter-I/subchapter-B/part-255>
- Rule on the Use of Consumer Reviews and Testimonials, 16 CFR Part 465: <https://www.ecfr.gov/current/title-16/chapter-I/subchapter-D/part-465>
- Part 465 final rule, 89 FR 68077: <https://www.ftc.gov/system/files/ftc_gov/pdf/r311003consumerreviewstestimonialsfinalrulefrn.pdf>
- Consumer Review Fairness Act, 15 U.S.C. § 45b: <https://www.law.cornell.edu/uscode/text/15/45b>
- COPPA Rule, 16 CFR Part 312: <https://www.ecfr.gov/current/title-16/chapter-I/subchapter-C/part-312>
- Section 230, 47 U.S.C. § 230: <https://www.law.cornell.edu/uscode/text/47/230>
- DMCA safe harbor, 17 U.S.C. § 512: <https://www.law.cornell.edu/uscode/text/17/512>
- Video Privacy Protection Act, 18 U.S.C. § 2710: <https://www.law.cornell.edu/uscode/text/18/2710>

**FTC guidance**

- FTC's Endorsement Guides: What People Are Asking: <https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking>
- Consumer Reviews and Testimonials Rule: Questions and Answers: <https://www.ftc.gov/business-guidance/resources/consumer-reviews-testimonials-rule-questions-answers>
- Featuring Online Customer Reviews: A Guide for Platforms: <https://www.ftc.gov/business-guidance/resources/featuring-online-customer-reviews-guide-platforms>
- Consumer Review Fairness Act: What Businesses Need to Know: <https://www.ftc.gov/business-guidance/resources/consumer-review-fairness-act-what-businesses-need-know>
- Enforcement Policy Statement on Deceptively Formatted Advertisements: <https://www.ftc.gov/legal-library/browse/commission-enforcement-policy-statement-deceptively-formatted-advertisements>
- Native Advertising: A Guide for Businesses: <https://www.ftc.gov/business-guidance/resources/native-advertising-guide-businesses>
- .com Disclosures: <https://www.ftc.gov/business-guidance/resources/com-disclosures-how-make-effective-disclosures-digital-advertising>
- Search engine advertising letters (press release, June 25, 2013): <https://www.ftc.gov/news-events/news/press-releases/2013/06/ftc-consumer-protection-staff-updates-agencys-guidance-search-engine-industry-need-distinguish>
- CAN-SPAM Act: A Compliance Guide for Business: <https://www.ftc.gov/business-guidance/resources/can-spam-act-compliance-guide-business>
- COPPA Rule page: <https://www.ftc.gov/legal-library/browse/rules/childrens-online-privacy-protection-rule-coppa>

**Guam law**

- 5 GCA Chapter 32, Trade Practices and Consumer Protection: <https://statecodesfiles.justia.com/guam/2024/title-5/division-3/chapter-32/chapter-32.pdf>
- 9 GCA Chapter 48, Notification of Breaches of Personal Information: <https://law.justia.com/codes/guam/title-9/chapter-48/>

**Other**

- California Online Privacy Protection Act, Bus. & Prof. Code §§ 22575–22579: <https://leginfo.legislature.ca.gov/faces/codes_displayText.xhtml?lawCode=BPC&division=8.&chapter=22.&part=&article=>
- GDPR Article 3 (territorial scope): <https://gdpr-info.eu/art-3-gdpr/>
- DMCA designated agent rule, 37 CFR § 201.38: <https://www.law.cornell.edu/cfr/text/37/201.38>
- US Copyright Office DMCA Designated Agent Directory: <https://www.copyright.gov/dmca-directory/>
- Benesch, "Five Stars, Zero Tolerance" (secondary source on Part 465 enforcement and penalty amount, April 2026): <https://www.beneschlaw.com/insight/five-stars-zero-tolerance-ftc-turns-up-enforcement-under-consumer-review-rule/pdf/>

**Not verified in this pass:** the exact current-year inflation-adjusted civil penalty; Japan APPI and Korea PIPA reach; any pending Guam privacy bill; current California CIPA and VPPA case law. Each is flagged for counsel above.
