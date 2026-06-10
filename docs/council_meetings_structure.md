# Piscataway Council Transcripts: Structural Reference for Analysis

Reference for parsing/analyzing the council meeting transcripts in `/home/piscataway/web/files/council/`. Covers meeting mechanics only — agenda structure, votes, ordinances, parsing anchors.

## Files

- 138 transcripts named `YYYY-MM-DD.txt`, January 2018 – May 2026.
- Format: `Speaker N    HH:MM:SS    text` (multiple spaces as separator). One paragraph per speaker turn.
- `Speaker N` labels are anonymous and **not stable across meetings** (Speaker 1 in one file ≠ Speaker 1 in another). Within a single meeting a label usually (not always) tracks one person.
- Ignore `.mp3` (audio), `.peaks.json` (waveform data). `.pdf` files are official minutes — sparse, but useful to cross-check vote outcomes and exact resolution numbers.
- Gap: no transcripts between 2020-02-18 and 2020-10-13 (COVID). 2020–2021 meetings were telephonic; expect garbled openings and "star nine / star six" mute instructions.

## Standard meeting skeleton (consistent across all years)

1. **Call to order + adequate-notice statement** (boilerplate citing "chapter 231, PL 1975").
2. **Roll call** — clerk reads each member's name; answers "here"/"present". Anchor phrases: "please take the roll call", "please call the roll/role".
3. **Pledge of allegiance**.
4. **Comments on adjournment of agenda matters** (almost always "None").
5. **Open to the public — consent agenda only** (comments restricted to consent items).
6. **Ordinances** — first readings and second readings, each with:
   - Clerk reads the resolution ("Be it resolved by the Township Council… an ordinance entitled…").
   - Second readings include a public comment period per ordinance (remote first, then in-person, from ~2022 on).
   - Offer (motion) + second, then **roll-call vote**.
   - Outcome phrase: "the ordinance passes (on first/second reading)" or rarely "fails".
   - Ordinances get sequential numbers: "this ordinance shall be assigned number YYYY-NN".
7. **Consent agenda resolutions** — single motion/second/roll-call vote for all lettered items (A, B, C… sometimes through Z+). Members may ask to remove items for separate vote ("bifurcate"); a removed item may be voted separately or tabled.
8. **Proclamations** — read by the mayor ("Whereas… Now therefore, I, Brian Wahler, mayor…"). No vote.
9. **Announcements and comments from officials** — each council member in turn, then mayor, then business administrator. Project status updates often appear here (road projects, grants, remediation), not in the voted items.
10. **Agenda session** — council president previews the next meeting's agenda items (useful for tracking items across meetings).
11. **Open to the public (general)** — speakers state name and address; 3-minute limit. Remote (Zoom/phone) and in-person handled as separate sub-portions in later years.
12. **Adjournment**.

Annual **reorganization meetings** (first meeting of January, e.g. 2018-01-02, 2023-01-03, 2026-01-02) differ: oaths of office, mass appointments to boards/commissions, professional services contract awards, designation of official newspapers/depositories/meeting schedule, council committee assignments. They are dominated by boilerplate.

## Roll-call votes — how to extract

- The clerk reads each name; votes are interleaved in the same or following speaker turns: "Council member Carmichael? Yes. Council member Espinosa? Yes. …"
- Transcription often merges the clerk's name-call and the member's answer into one turn; a "No" vote usually appears as a separate short turn.
- Order is alphabetical, council president last.
- Title drift: "Councilman/Councilwoman/Council member" all occur. Match on surname.
- Votes were unanimous (6–0 / 7–0) essentially always through 2024; non-unanimous votes appear from 2025 onward, so don't assume unanimity.

## Council roster by era (from roll calls; mayor Brian Wahler throughout)

- **2018–2020**: Bullard, Cahn, Cahill, Lombardi, Shaw, Uhrin, Carter/McCollum (7 members). Clerk: Melissa Cedar.
- **2021–2024**: Bullard, Cahn (later Cahn Rouse), Cahill, Lombardi, Shaw, Uhrin, Carmichael (joins ~2023), Espinoza (joins ~2024). Council president rotates (Cahill, Shaw…).
- **2025–2026**: Cahill, Carmichael, Espinoza, Liebowitz, Rashid, Uhrin; Lombardi council president in 2026. Business administrator: Timothy Dacey through ~2024, then Paula Elli ("Paulelli"?). Township attorney: Raj Goomer (rendered "Gomer/Gummer/Groomer/Goodbar").

## Transcription noise — normalization glossary

Names mangle systematically; normalize before matching:

| Canonical | Variants seen |
|---|---|
| Liebowitz | Leitz, Lebowitz, Libert, Liebert |
| Cedar (clerk) | Theater, SITA, Ms. The, Msed, Peter |
| Uhrin | rn, Ern, earn, urban, Rin |
| Goomer (attorney) | Gomer, Gummer, Groomer, Goodbar, Gumar |
| Cahill | Kay hill, kale, Caho |
| Cahn | con, Kahn, Han |
| Dacey | Daisey, Daisy, Dacy, Gacy |
| Wahler | Waller, Walton, Powell Body |
| Piscataway | Skyway, the Skyway, Prescott away, Pisca, scattery, Paskataway |
| Espinoza | Espinosa |
| Rashid | Rasheed |

Also: numbers are often spelled out or split ("2 87" = Route 287; "$575,100 and 63 cents"); statute citations render as "N J S a 40 colon 69 a dash 1 8 4".

## Recurring voted-item categories

- Traffic/parking ordinances (chapter 7), zoning amendments (chapter 21), police regulations (chapter 3), licensing (chapter 4).
- Capital appropriations ("fully funded $X from the Capital Improvement Fund / Capital Surplus Fund") — sewer, roads, equipment.
- Annual items: liquor license renewals (June), best-practices inventory (fall), budget adoption (spring), tax-levy and bond ordinances, street resurfacing contract awards, NJDOT local-aid projects, recycling/Clean Communities grant applications.
- Consent agenda staples: grant acceptances, contract awards/renewals, refunds (picnic fees, inspection fees), change orders, tax appeals, raffle licenses.
- Faulkner Act initiative petitions appear in Aug–Nov 2021 (clerk certification → council consideration → ballot).
- Redevelopment plan adoptions (block/lot designations) cluster in 2021–2022.

## Pitfalls

- Public commenters state name + address at the podium — the only reliable speaker IDs for non-officials.
- The mayor and council members are identified when the chair calls on them ("Councilwoman Rashid?") — the *next* turn is usually that person.
- Consent agenda items are referred to by letter (item "13Z") and the letters reset every meeting; the underlying resolution text is often not read aloud unless a resident requests it.
- Some ordinances are introduced, then amended/split/tabled across meetings — track by ordinance number (YYYY-NN) once assigned, or by chapter/section reference before assignment.
- Meeting dates in filenames are authoritative; spoken dates in openings are sometimes misrecognized.
