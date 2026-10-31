# Review checklist

The review stage and the fix round read this list. The reviewer applies it to the whole change. The fix worker applies it to every change of its round, committed or not, before it pushes.

## What to look for

Look for these, most important first:

1. A bug: wrong logic, a missing case, a failure that no test catches, a race, a security hole.
2. A break of a convention that an instruction file of the repository names.
3. A missing test for new behaviour.
4. Dead code and needless complexity. A branch or a condition that a correct fix needs is not needless: `loupe-recommendations` "In a review" says what counts.
5. The spec pass of the review stage. Run it for each pull request, after the checks above. Its requirement source is the approved designs and the prototype of the card. A card with no design and no prototype uses the card body.

## Name the rule, then find every site

A problem often has a clear rule behind it. The rule is an invariant, a design decision, a convention or a missing test. Fixing one instance leaves the other places where the rule applies.

1. For each problem, ask what rule the code breaks. Not every problem has a clear rule. A typo or a wrong constant has none, so report it as a plain finding.
2. When a rule exists, search the whole diff for every other place where it applies. Read each place. Do not stop at the first one you saw.
3. State the rule precisely. "A token is live only while its card can show a preview" is a rule. "The token code is fragile" is not.

## New surface

Code that is new in the change, and most of all code that a fix added, is new surface. Fix code gets the least thought. For each of these, ask what it leaves out:

1. New locking or ordering: which other path changes the same state without the lock or the order? Look at finish, delete, revoke and move paths.
2. A new branch of logic: which input or state does no branch cover?
3. A new state or a new status value: which code reads the old set of values, and which code never leaves the new state?
4. A new check: which path reaches the same data without the check?
