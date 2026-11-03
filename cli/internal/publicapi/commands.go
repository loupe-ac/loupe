package publicapi

// Commands names each operation that has a command. A new API route adds one
// line here, and `just cli-generate` adds its flags.
var Commands = []Command{
	{Path: []string{"project", "list"}, Op: "listProjects", Columns: []string{"id", "slug", "name"}},
	{Path: []string{"project", "get"}, Op: "getProject"},
	{Path: []string{"column", "list"}, Op: "listBoardColumns", Columns: []string{"slug", "label", "terminal"}},
	{Path: []string{"column", "get"}, Op: "getColumn"},
	{Path: []string{"card", "list"}, Op: "listCards", Columns: []string{"number", "title", "status", "type", "updatedAt"}},
	{Path: []string{"card", "get"}, Op: "getCard", LongText: "body"},
	{Path: []string{"card", "history"}, Op: "listCardEvents", Columns: []string{"occurredAt", "kind"}},
	{Path: []string{"feedback", "list"}, Op: "listFeedback", Columns: []string{"id", "status", "number", "title", "createdAt"}},
	{Path: []string{"feedback", "get"}, Op: "getFeedback", LongText: "body"},
	{Path: []string{"document", "list"}, Op: "listDocuments", Columns: []string{"documentId", "title", "status", "version", "createdAt"}},
	{Path: []string{"document", "get"}, Op: "getDocument", LongText: "markdown"},
	{Path: []string{"document", "review"}, Op: "getDocumentReview"},
	{Path: []string{"document", "version", "list"}, Op: "listDocumentVersions", Columns: []string{"versionNumber", "createdAt", "threadCount", "description"}},
	{Path: []string{"document", "version", "get"}, Op: "getDocumentVersion", LongText: "markdown"},
	{Path: []string{"artifact", "list"}, Op: "listArtifacts", Columns: []string{"artifactId", "name", "selectedVersion", "archived", "createdAt"}},
	{Path: []string{"artifact", "get"}, Op: "getArtifact"},
}

const nextChild = "the next child builds it"

// Exceptions names each operation that has no command, with the reason.
var Exceptions = []Exception{
	{Op: "getOpenApiDocument", Reason: "needs no sign-in, use curl"},
	{Op: "listApiVersions", Reason: "needs no sign-in, use curl"},
	{Op: "createProject", Reason: nextChild},
	{Op: "updateProject", Reason: nextChild},
	{Op: "createBoardColumn", Reason: nextChild},
	{Op: "updateBoardColumn", Reason: nextChild},
	{Op: "deleteBoardColumn", Reason: nextChild},
	{Op: "reorderBoardColumns", Reason: nextChild},
	{Op: "createCard", Reason: nextChild},
	{Op: "updateCard", Reason: nextChild},
	{Op: "holdCard", Reason: nextChild},
	{Op: "releaseCard", Reason: nextChild},
	{Op: "releaseCardPause", Reason: nextChild},
	{Op: "markFeedbackAddressed", Reason: nextChild},
	{Op: "createDocument", Reason: nextChild},
	{Op: "updateDocument", Reason: nextChild},
	{Op: "publishDocument", Reason: nextChild},
	{Op: "createDocumentVersion", Reason: nextChild},
	{Op: "submitDocumentVerdict", Reason: nextChild},
	{Op: "withdrawDocumentVerdict", Reason: nextChild},
	{Op: "answerDocumentDecision", Reason: nextChild},
	{Op: "clearDocumentDecision", Reason: nextChild},
	{Op: "replyToDocumentComment", Reason: nextChild},
	{Op: "markDocumentCommentAddressed", Reason: nextChild},
	{Op: "createArtifact", Reason: nextChild},
	{Op: "createArtifactVersion", Reason: nextChild},
	{Op: "updateArtifact", Reason: nextChild},
	{Op: "readArtifactFile", Reason: nextChild},
}
