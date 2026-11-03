# Product design checklist

The design review stage applies this list to a draft product design. Each check can give a finding. Give the severity `important` only with evidence.

## The checks

1. Answers and prototype: Each answer in the decisions log matches the prototype that the owner picked. No two decisions contradict each other. No two `R` entries contradict each other. An answer that the prototype contradicts, and two answers that conflict, are findings with `needsPerson`.
2. Scenarios and requirements: Each scenario cites `R` entries that exist. Each `R` entry has at least one scenario.
3. Claims about today: Each claim about how the product or the code behaves today is true. Read the code, the docs or the board to check it. A claim you cannot check is a `nit` that says so.
4. Writing and template: The document follows the writing rules of the profile and the product document template in `../../loupe-stage-product-design/references/product-document.md`. A section that the template requires is present, or the document says why it is left out.
5. Expected behaviour: Each decision follows the design principles D1 to D3 in `../../loupe-stage-product-design/references/design-principles.md`. A decision that surprises a user, or misses the intent behind an action or a state, is a finding with `needsPerson`. When the decisions log shows that the owner chose it knowingly, it is no finding.

## How to look

1. Read the decisions log first. Then read the `R` entries and the scenarios against it.
2. Check the stable IDs. A scenario that cites `R7` when the document has six `R` entries is a finding.
3. Check each sentence of "Current behaviour" against the code. Name the file you read.
4. A choice that only the owner can make is not a defect of the document. It belongs in "Open questions" with a decision fence. A missing fence for such a choice is a finding.
5. Do not flag a matter of taste, a style that the formatter enforces, or a decision that the owner already answered.
