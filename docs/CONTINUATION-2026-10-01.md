# S Hari Global — continuation, १ अक्टोबर २०२६

## आधार
PR #14 खुला draft; Deploy #48 success र Application checks #192 failure दुवै `claude/project-thread-rg55gt`, commit `489af7d674ef0f07df55bf69008dba43d216812d` हुन्। Default branch प्रयोग गरिएन। नयाँ branch `codex/continuation-fixes-20261001`, PR #16। AGENTS.md/REMAINING-WORK.md यस commit मा छैनन्। CLAUDE.md, audit/continuation docs र workflows पढिए। Claude/VPS working tree पहुँच छैन; reset/overwrite/deploy गरिएको छैन।

## सुधार
- CI मा nginx समान router: dynamic sitemap XML suite अब पास, पहिलो rerun battery 106/109 बाट 107/109।
- Failure output को 25-line कटौती हट्यो; assertions, exit policy र historical exclusions परिवर्तन छैन।
- WhatsApp FAQ ले existing Boarding helper बाट Baroda/Vadodara/Barauda alias मिलाउँछ।
- TicketBot fixture ले दुई cancelled र तीन confirmed sales आफैँ बनाउँछ; pre-existing rows मा निर्भर छैन। FAQ fixture पनि सही route priority र alias ले खोज्छ।
- Website WhatsApp request ले manual wa.me fallback लाई Sent भन्न छोड्छ: boolean true मात्र API सफलता। तीन return cases endpoint harness मा पास।
- मालिकले पुष्टि गरेको homepage Trade card हट्यो; bus card full width, PNG ticket wording। Agent/counter attribution वा records हटाइएनन्।

## Existing feature inventory
CI pass को अर्थ isolated fixtures मा काम गर्छ; live provider/configuration प्रमाण होइन।

| Feature | Existing code / प्रमाण | अवस्था |
|---|---|---|
| Booking / quick ticket | booking.php, quickticket.php, e2e-booking-test | CI pass; shared logic कायम |
| Seat lock / double booking | seats.php, double-booking-race, cross-mode-seat-sync, seat-events suites | CI pass; real VPS load बाँकी |
| Payment / permissions | booking tests, role-gates | CI pass; real payments चलाइएनन् |
| Agent/counter/admin commission | agentwallet.php, agent-wallet, counter-role | CI pass; live reconciliation बाँकी |
| Nepali PNG / QR | ticket.php, devshape.php, ticket-png suite | CI pass; actual handset receipt बाँकी |
| WhatsApp retry / office alert | notify.php, cron/whatsapp-retry.php, wa-fail-alert.php | CI suites pass; live cron/provider बाँकी |
| Webhook signature | webhook.php HMAC/hash_equals | Code मा fail-closed; real signed delivery बाँकी |
| AI / FAQ | aiagent.php, ticketbot.php, wafaq.php | Failures का कारण सुधार; external quality/config बाँकी |
| Local AI fallback | provider ladder | Ollama/llama wiring भेटिएन; endpoint/model पहुँच चाहिन्छ |
| GPS | track.php र existing APIs | Code छ; real telemetry/authorization verification बाँकी |
| Reports | existing report handlers / AiChart | Local rendering 15/15; live accounts बाँकी |
| Backup | cron/backup.php, backup-offsite.php | CI encryption suite pass; real restore drill बाँकी |
| Drive/cloud sync | existing code/notes | Authenticated completion प्रमाण छैन; live configuration चाहिन्छ |

## Storage / performance
Assets लगभग 5.1 MiB। दुई ठूला Devanagari fonts का अलग references devshape.php मा छन्; duplicate भनेर हटाइएनन्। VPS disk usage वा unused uploads प्रमाण छैन। कुनै DB, ticket, proof, upload, config, backup वा asset हटाइएको छैन।

यस workspace बाट home HTTP 200: TTFB 9.783, 10.823, 9.017 s; total 10.533, 11.523, 9.681 s। Dynamic sitemap HTTP 200, TTFB 11.438 s। यी client/network समावेश भएका measurements हुन्; PHP/DB समय होइनन्। Production परिवर्तन नभएकाले before/after speed दाबी छैन। API/DB timing, EXPLAIN/indexes, PHP-FPM/nginx/cron र real mobile flow बाँकी। Offline tests ले API/payment proofs cache हुँदैन भन्ने पुष्टि गर्छन्।

## Local tests
PHP 8.3 syntax सबै source पास; offline ticket 21/21, lazy retry, i18n 803 keys प्रत्येक EN/HI/NE, AI turn 11/11, chart 15/15, asset versions 9/9, sender-result 3/3। MariaDB initialize भयो तर environment ले socket खोल्न दिएन; पूरा battery GitHub CI मा। Latest commit CI conclusion PR मा हेर्नुहोस्।

## Deployment / rollback — स्वीकृति र live पहुँच बाँकी
1. Exact reviewed commit freeze; push/PR checks green। VPS git status र Claude session जाँचेर dirty changes सुरक्षित राख्ने।
2. Files/config snapshot र DB dump off-web-root मा, checksums/offsite copy; isolated restore drill र record counts जाँच। Tickets/proofs सुरक्षित।
3. `489af7d` code rollback reference हो; वास्तविक live files/config/DB snapshot पनि चाहिन्छ। Dirty live state मा code-only rollback पर्याप्त छैन।
4. Exact branch/commit, backup locations/checksums र rollback steps review पछि मालिकको स्पष्ट approval। त्यसअघि deploy workflow नचलाउने।
5. Approved dry review, full deploy (यस PR मा migration छैन), booking/seat/payment/PNG mobile smoke, cron check; failure भए verified snapshot restore।

## Blockers
Hostinger servers registered छन् तर callable tools/SSH यस session मा उपलब्ध छैनन्। Live storage cleanup, DB/nginx/PHP tuning, cloud-sync, local AI installation, actual GPS test, restore drill र after-performance उपलब्ध पहुँचमा पूरा हुन सकेनन्। नयाँ duplicate system बनाइएको छैन।
