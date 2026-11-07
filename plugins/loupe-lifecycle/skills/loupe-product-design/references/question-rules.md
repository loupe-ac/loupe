# Question rules

Q1, Q2, Q3, Q7 and Q8 live in `../../loupe-interviewing/SKILL.md`. This file holds the two rules that only a product design session uses, and the coverage checklist. Each rule has a stable ID, so a comment can cite it. The ID Q4 is retired. Do not reuse it, so the other IDs keep their meaning.

## Rules

1. Q5: Send look and feel to a mockup. A question about how something looks gets a sketch or a prototype. Do not answer it with more questions. Follow `claude-design.md`. The first visual choice of the session gets the offer, and a later one uses the project or the sketches of the first answer.
2. Q6: Park technical choices. An entity, a table, an API or a module goes to the "For tech design" section. Do not settle it in the session.

Write each answer in the "Decisions log" section, with the question. Write each Q7 answer in the "Priorities" section too. Write each decision that the owner defers in the "Open questions" section. Write a guess in the "Assumptions" section only when no code, doc, command or board history can check it, such as how users will act. Check every other guess, and write the result as a fact.

## Coverage checklist

P6 works through this checklist. Mark each area Clear, Partial or Missing for yourself, and do not show the marks to the owner. Ask about a Partial or Missing area when its answer changes the behaviour. Ask first about the area with the highest impact and the most uncertainty.

1. Functional scope. What the change does, and what it leaves out.
2. Users and roles. Who uses the change, and which roles the product already has for them.
3. Main journeys. The steps a user takes from the trigger to the result.
4. Empty states. What a user sees before any data exists.
5. Error states. What a user sees when an action fails, and how the user recovers.
6. Edge cases. Limits, duplicates, concurrent edits, and a removed or renamed item.
7. Permissions. Who may see, create, change and delete each thing.
8. Data lifecycle. How data is created, changed, archived, deleted and exported.
9. Non-functional expectations. Limits, volume, speed a user notices, and availability.
10. Integrations. The APIs, jobs, email and external services that the change touches.
11. Terminology. The names a user sees, and whether they match the names the product already uses.
12. Completion signals. How a user knows that the action worked.
13. Documentation checks. The checks that the routed skills and the instruction files name. When they name none, skip this area.
14. Design system. This area applies to a change that touches the user interface, when the routed skills or the instruction files name a design system. It covers the components the change reuses, and the components it must change. Settle each component change with the owner.
