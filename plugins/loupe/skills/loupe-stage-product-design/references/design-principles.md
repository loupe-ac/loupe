# Design principles

Apply these principles to each product decision. That covers a recommendation in a session, a revision in a review round, and a finding in a design review. Each principle has a stable ID, so a comment can cite it.

1. D1: Find the intent. Before you decide what an action or a state does, ask why a user takes that action or reaches that state. Design for that goal. For example, a user who archives a card wants it off the board and wants to find it again later.
2. D2: Meet the expectation. Ask what a person in that situation expects to happen next. Use the habits that the product and similar products already teach the user. When the code makes a different result easier, the expectation still wins in the product document. Park the cost in "For tech design" (Q6).
3. D3: Least surprise. Prefer the option that surprises the user least. When an option must surprise, the user sees what happened and how to undo it.

## How to apply them

1. Give the intent (D1) and the expectation (D2) in the reason of each recommendation (Q2).
2. When two options meet the same intent, recommend the one with less surprise (D3).
3. When the owner picks an option that breaks a principle, name the principle and the surprise once. Then follow the answer, because the owner decides.
