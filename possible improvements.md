# Possible improvements

Ideas for future work on RapidCMI5 management. These are deferred suggestions, not an implementation commitment.

## RapidCMI5 Projects management card

The card currently shows only a title and description. Make it a useful summary of project activity with a clear path into managing projects.

- [ ] **Project count:** Show how many projects are being managed.
- [ ] **Deployment summary:** Show how many projects have deployed activities and how many have none.
- [ ] **Recent activity:** Show the most recently updated project and when it changed.
- [ ] **Clear actions:** Use “View projects” as the main action and add an “Upload package” shortcut.
- [ ] **Distinct visual identity:** Add a projects icon, stronger title hierarchy, and consistent spacing, hover, and keyboard-focus states.
- [ ] **Helpful empty state:** Explain how to get started when no projects exist and prominently offer “Upload your first package.”

## Projects page

- [ ] **More useful project summaries:** Show version count and deployed activity count alongside the current version.
- [ ] **Search and sorting:** Add sorting by name and last updated, a result count, and a clear-search action.
- [ ] **Deployment filters:** Find projects with no deployments or activities using older project versions.
- [ ] **Clearer labels and missing-data states:** Replace “repo” with “View repository” and explicitly show “No version” where appropriate.
- [ ] **Better small-screen layout:** Prevent long identifiers and repository links from making the table difficult to use.
- [ ] **Separate empty and no-results states:** Distinguish “You haven’t uploaded any projects” from “No projects match your search.”

## Suggested starting point

Start with the management card’s project count, recent activity, and two clear actions. Then improve the projects list with deployment counts and sorting. Keep the dashboard concise.
