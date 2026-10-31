# Tech design checklist

The design review stage applies this list to a draft tech design and to its deploy notes. Each check can give a finding. Give the severity `important` only with evidence.

## The five checks

1. Coverage: The design covers each `R` entry and each scenario of the approved product document. It adds no behaviour that the product document did not ask for. With no product document, the card body is the source. An `R` entry that nothing in the design builds is an `important` finding that names the entry.
2. Claims about the code: Each claim about the code is true, or the design marks the thing as new. Read the file behind each claim. Search for the count that a sentence states. A wrong class, route, field or behaviour is an `important` finding with the file and line.
3. Conventions and good practice: The design follows the conventions and the decision records that the instruction files of the profile name, and general good practice. Name the rule and its source in the finding.
4. Libraries and services: Each claim about a library or a third-party service is true. Check the current documentation, with Context7 or a web search when the tools exist. A claim you cannot check is a `nit` that says so.
5. Writing and deploy notes: The design and the deploy notes follow the writing rules of the profile. The deploy notes list each step that the design implies, as the deploy items of the profile and `../../loupe-stage-tech-design/references/tech-document.md` "Deploy notes" define them. A deploy item of the design that the notes lack is a finding.

## How to look

1. Read the product document and its answers first. Then read the design against them.
2. Read each decision of the design as a reader who opens only that section, as `loupe-documents` rule 18 says. A decision that leans on another section is a finding.
3. Check that each decided option follows the answer of the owner. The answer wins over the text.
4. Check a Breakdown, when the design has one. Give an `important` finding for each of these problems:
   1. A requirement that the Breakdown does not give to one child.
   2. An entry that names a loop of blockers.
   3. An entry that reads as post-epic work and has no `Follow-up.` marker. Use the test of `../../loupe-stage-implementation/references/breakdown.md` "Post-epic work".
   4. A follow-up entry whose `Blocked by` names a child entry.
5. A design that is right where the product document is wrong is a finding with `needsPerson`, because only the owner can change the product document.
6. Do not flag a matter of taste, a style that the formatter enforces, or a decision that the owner already answered.
