# Liberty Classroom content mapping

Audit date: 2026-08-30  
Source: local production clone at `https://libertyclassroom.test`  
Scope: published LearnDash curriculum, its media and taxonomy, and the MemberPress-to-LearnDash access matrix. No WordPress content was changed during this audit.

## Recommended target model

Liberty Classroom has a straightforward migration shape:

```text
LearnDash course                 -> Library Course
LearnDash ordered lesson         -> Library Content item placed in that Course
LearnDash course tag (person)    -> Library Speaker
LearnDash course category        -> Library Collection or editorial badge
LearnDash group                  -> Library Access Group
MemberPress product assignment   -> Membership assigned to that Access Group
```

There are no live LearnDash topics, quizzes, or section headings to reproduce. Each live course is therefore one ordered list of lessons. The migration should be additive: create new private Library records, retain stable pointers to the LearnDash source records, verify the resulting content/access matrices, and leave the existing LearnDash catalogue untouched until cutover is approved.

## Course, Series, and Collection structure

### Initial launch structure

The initial Liberty catalogue should contain:

| Library object | Initial count | Purpose |
|---|---:|---|
| Courses | 39 | Bounded, ordered curricula with lesson progress and completion |
| Series | 0 | None of the current LearnDash catalogue is an ongoing episodic publication |
| Content items | 1,227 | Lessons placed in Courses, each with structured video/audio media |
| Speakers | 14 | Instructor profiles related to Courses and inherited by lessons |
| Primary Collections | 5 | Member-facing browse shelves that organize Courses without changing access |
| Access Groups | 3 | Basic, Basic Plus, and Master entitlement matrices |

Audio is a playback mode, not a catalogue type. A lesson with video and MP3 alternatives remains one Content item with one progress record and two structured media sources. It must not become a duplicate podcast episode or a separate audio-only course.

### When to use a Course

Use a Course when the material:

- has a defined curriculum;
- is intended to be consumed in a meaningful order;
- has a beginning and an expected completion point; and
- should report lesson/course progress.

All 39 current LearnDash records meet this definition, including the 165- and 180-lesson Western Civilization courses.

### When to use a Series

Use a Series only for an episodic publication whose membership changes over time, for example a weekly lecture feed, recurring live Q&A, monthly book club, or podcast archive. A Series may be ongoing, normally emphasizes newest/oldest episode ordering rather than curriculum completion, and can be followed without implying that the member must “complete” the whole archive.

Do not use a Series merely because:

- lessons have audio;
- a Course is long;
- several Course titles contain “Part I”, “Part II”, or “Part III”; or
- Courses should appear together on a browse page.

The current paired and multipart Courses should remain distinct Courses at migration. Their relationships belong in Collections and homepage curation. Combining or restructuring them during the same migration would make source-to-target comparison unnecessarily ambiguous.

### Proposed primary Collections

Start with one primary Collection per Course so the initial browse structure is understandable and testable. Reusable Topics can later provide cross-cutting filters without duplicating Courses.

#### Economics — 8 Courses

- American Economic History, Parts I–III
- Austrian Economics, Step by Step
- History of Economic Thought, Parts I–II
- John Maynard Keynes: His System and Its Fallacies
- What's Wrong with Textbook Economics

#### American History & Government — 10 Courses

- How Alexander Hamilton Screwed Up America
- Introduction to Government
- The 10 Worst and 10 Best Presidents
- The American Revolution: A Constitutional Conflict
- The Early Republic, 1807-1820
- The Thomas Jefferson Nobody Knows
- Trails West: How Freedom Settled the West
- U.S. Constitutional History
- U.S. History to 1877
- U.S. History Since 1877

#### Western Civilization & World History — 8 Courses

- Colonial Latin American History
- Crimes of Communism
- History of England
- The History and Heritage of Western and American Civilization
- Western Civilization to 1492
- Western Civilization From 1493
- Western Civilization to 1500
- Western Civilization Since 1500

#### Political Thought & Ideas — 7 Courses

- A History of Free Thought
- Critical Theory, Cultural Studies, and Postmodern Theory
- Freedom's Progress: The History of Political Thought, Parts I–II
- Introduction to Logic
- The Great Reset
- The History of Conservatism and Libertarianism

#### Literature, Myth & Science Fiction — 6 Courses

- Introduction to American Literature: Our Best Short Stories
- Libertarianism and Science Fiction: The Golden Age from Bradbury to Roddenberry
- Little Houses of Liberty: Laura Ingalls Wilder’s Literary Genius
- Mythology and Western Civilization: From Plato to Tolkien
- Science Fiction, Liberty, and Dystopia, Parts I–II

These Collections are editorial navigation only. They must not grant access or replace Basic/Basic Plus/Master Access Groups. In particular, the two parallel Western Civilization pairs represent different course offerings and access tiers; they must remain separate records even though they occupy the same Collection.

### Course sections

The initial migration should preserve each source course as an unsectioned ordered curriculum because LearnDash supplies no section boundaries. This is faithful but may be unwieldy for the 90-, 165-, and 180-lesson Courses. Sectioning those Courses should be a later editorial enhancement using meaningful historical units—not mechanically generated chunks—and should not change lesson UUIDs, media, progress, or access.

## Source inventory

| Source object | Published | Migration treatment |
|---|---:|---|
| LearnDash courses | 39 | Create 39 Library Courses |
| Lessons in the 39 published course trees | 1,227 | Create ordered Library Content items |
| Other published LearnDash lessons | 93 | Exclude by default; retain in an exception report |
| LearnDash topics | 0 | No target records |
| Course sections | 0 | Use one unsectioned ordered curriculum per course |
| Course categories | 3 | Map deliberately; do not treat all as the same concept |
| Speaker-like course tags in use | 14 | Create 14 Library Speaker profiles |
| LearnDash access groups | 3 | Create Basic, Basic Plus, and Master Access Groups |
| Published MemberPress products | 4 | Three live tiers plus one test product |
| Draft MemberPress products | 1 | Preserve only as a reviewed special case |

The 1,227 live lesson IDs are unique across course trees and have no course-ID mismatches. The extra 93 published lesson records consist of 59 pointing at deleted course `27104`, nine pointing at deleted course `67380`, two pointing at deleted course `82240`, and 23 with no course ID. They are not part of any published LearnDash course tree and must not inflate the migrated catalogue.

## Course catalogue

The lesson count below is the authoritative count from each published LearnDash course tree, not a loose query against lesson metadata.

| Order | Course | Lessons | Category | Speaker(s) |
|---:|---|---:|---|---|
| 0 | American Economic History, Part I | 26 | Core | Jeffrey M. Herbener |
| 0 | History of England | 14 | Core | Jason Jewell |
| 0 | Introduction to American Literature: Our Best Short Stories | 20 | Core | Elizabeth Kantor |
| 1 | American Economic History, Part II | 15 | Core | Jeffrey M. Herbener |
| 2 | American Economic History, Part III | 10 | Core | Jeffrey M. Herbener |
| 3 | A History of Free Thought | 18 | Core | Michael Rectenwald |
| 4 | Austrian Economics, Step by Step | 35 | Core | Jeffrey M. Herbener |
| 5 | Colonial Latin American History | 10 | Core | Dedra McDonald Birzer |
| 6 | Crimes of Communism | 13 | Core | Jason Jewell |
| 7 | Critical Theory, Cultural Studies, and Postmodern Theory | 12 | Core; Free Courses | Michael Rectenwald |
| 8 | Freedom's Progress: The History of Political Thought, Part I | 42 | Core | Gerard Casey |
| 9 | Freedom’s Progress: The History of Political Thought, Part II | 59 | Core | Gerard Casey |
| 10 | History of Economic Thought, Part I: Classical Economics and the Marginal Revolution | 23 | Core | Robert Murphy |
| 11 | History of Economic Thought, Part II: 20th Century Economics | 24 | Core | Robert Murphy |
| 12 | How Alexander Hamilton Screwed Up America | 12 | Core; Free Courses | Brion McClanahan |
| 13 | Introduction to Government | 90 | Master Exclusive | Tom Woods |
| 14 | Introduction to Logic | 20 | Core | Gerard Casey |
| 15 | John Maynard Keynes: His System and Its Fallacies | 32 | Core | G.P. Manish |
| 16 | Libertarianism and Science Fiction: The Golden Age from Bradbury to Roddenberry | 15 | Core | Bradley Birzer |
| 17 | Little Houses of Liberty: Laura Ingalls Wilder’s Literary Genius | 14 | Core | Dedra McDonald Birzer |
| 18 | Mythology and Western Civilization: From Plato to Tolkien | 17 | Core | Bradley Birzer |
| 19 | Science Fiction, Liberty, and Dystopia, Part I | 16 | Core | Bradley Birzer |
| 20 | Science Fiction, Liberty, and Dystopia, Part II | 16 | Core | Bradley Birzer |
| 21 | The 10 Worst and 10 Best Presidents | 22 | Core | Brion McClanahan |
| 22 | The American Revolution: A Constitutional Conflict | 19 | Core | Kevin R. C. Gutzman |
| 23 | The Early Republic, 1807-1820 | 20 | Core | Bradley Birzer |
| 24 | The Great Reset | 7 | Core | Michael Rectenwald |
| 25 | The History of Conservatism and Libertarianism | 30 | Core; Free Courses | Jason Jewell |
| 26 | The History and Heritage of Western and American Civilization | 2 | Core | Bradley Birzer |
| 27 | The Thomas Jefferson Nobody Knows | 13 | Core | Kevin R. C. Gutzman |
| 28 | Trails West: How Freedom Settled the West | 14 | Core | Bradley Birzer |
| 29 | U.S. Constitutional History | 30 | Core | Brion McClanahan; Kevin R. C. Gutzman |
| 30 | U.S. History to 1877 | 30 | Core | Brion McClanahan; Kevin R. C. Gutzman; Tom Woods |
| 31 | U.S. History Since 1877 | 27 | Core | Hunt Tooley; Jonathan Bean; Kevin R. C. Gutzman; Tom Woods |
| 32 | Western Civilization to 1492 | 180 | Master Exclusive | Tom Woods |
| 33 | Western Civilization From 1493 | 165 | Master Exclusive | Tom Woods |
| 34 | Western Civilization to 1500 | 42 | Core | Jason Jewell |
| 35 | Western Civilization Since 1500 | 42 | Core | Jason Jewell |
| 36 | What's Wrong with Textbook Economics | 31 | Core | Jeffrey M. Herbener |

Three newer courses share `menu_order = 0`. The importer must preserve their internal lesson order but should not pretend their course-level order is unambiguous. Course ordering should be curated in the new Library homepage/collection controls after import.

## Fields and relationships

### Courses

- Preserve the current unique WordPress slug, title, status, and source post ID.
- Copy the featured image. All 39 courses have an attachment and its local source file is present.
- Create ordered Speaker relationships from the 14 person-name course tags. Every live course has at least one such tag.
- Preserve category membership as migration metadata, then deliberately configure Collections/access badges rather than blindly converting taxonomy semantics.
- Do not expect usable summaries from the source: none of the 39 courses has an excerpt and only one has meaningful body copy. Course introductions will require an editorial pass.
- Store the LearnDash ID as the legacy source ID and use a deterministic migration key/version so a rerun adopts or updates records instead of duplicating them.

### Lessons

- Create one Library Content item for each lesson in the authoritative course step list.
- Preserve the LearnDash step order as the Library item position.
- Preserve title, slug, status, source modified time, source ID, and sanitized descriptive HTML.
- Do not create sections: no source course has LearnDash sections or topics.
- Default each item to inherit its Course speakers.
- Store the containing Course as the parent relationship and the LearnDash lesson ID as the legacy source ID.

Nine History of England lessons have media-only bodies whose text becomes empty after stripping HTML. They are not empty records: their bodies contain playback markup and should be parsed before sanitization.

## Media and resources

The live curriculum is highly consistent:

- 1,225 lessons contain a `<video>` element.
- 1,224 contain an `<audio>` element.
- 1,224 contain both video and audio.
- Two exceptional items are download-only audio lessons in *The History and Heritage of Western and American Civilization*.
- 1,223 lesson bodies reference MP3 files.
- Lesson 26930 uses its MP4 video URL in both the video and audio elements; it correctly normalizes to one video source rather than a fake duplicate audio source.
- 165 lesson bodies reference PDFs; there are also PPT/PPTX and ZIP references.
- 26 lessons reference the WordPress uploads path directly.
- All 39 course thumbnail files are locally available, including the 37 whose generated URL currently points at Liberty's S3 host.
- No `.vtt` or `.srt` transcript files were found in uploads. Three lesson bodies merely contain the word “transcript” or a transcript-like reference; they are not a transcript corpus.

Migration should parse playback markup before sanitizing lesson HTML:

1. Convert the video source into the first/primary structured media row.
2. Convert the MP3 source into a secondary audio media row.
3. Convert PDFs, slides, ZIP files, and explicit download links into ordered Library resources where possible.
4. Keep ordinary reading-list links in the sanitized lesson description.
5. Remove migrated player markup from the description to avoid duplicate players.
6. Bundle locally owned upload files in any environment-migration ZIP; retain genuinely external stable URLs as external resources.

The source contains 1,349 plain-HTTP link/source references and 264 relative references. It also depends heavily on `libertyplatform.s3.amazonaws.com` and includes 455 references to `platform.tomwoods.com`. Before production cutover, the generated migration preview must report unresolved/unsafe URLs and playback should be sampled across every source-host pattern.

Transcripts are a separate follow-on project. The migration must leave transcript fields empty and must not imply that the AI assistant has transcript coverage.

## Access matrix

The current live access model is much cleaner than the broader WordPress site:

| MemberPress product | LearnDash assignment | Courses | Proposed Library Access Group |
|---|---|---:|---|
| Liberty Classroom BASIC Membership | LearnDash group `Basic` (`27047`) | 35 | Basic |
| Liberty Classroom BASIC PLUS Membership | LearnDash group `Basic Plus` (`27049`) | 36 | Basic Plus |
| Master | LearnDash group `Master` (`27051`) | 39 | Master |
| Test Membership | No LearnDash course/group assignment | 0 | None; exclude from production mapping |
| Two Free US History Courses (draft) | Direct courses `24154`, `24155` | 2 | Draft special-access group only if retained |

The tier differences are deterministic:

- Basic includes 35 courses.
- Basic Plus adds *Introduction to American Literature: Our Best Short Stories*.
- Master adds the three Master Exclusive courses: *Introduction to Government*, *Western Civilization to 1492*, and *Western Civilization From 1493*.

The proposed Access Groups must reproduce this exact 35/36/39 matrix and use MemberPress product slugs as portable assignments. Migration should stage the groups as unpublished/draft, run an exact matrix comparison, and publish them only after zero missing and zero excess grants are reported. Existing LearnDash enrolment remains untouched during validation.

The `Free Courses` category is editorial metadata, not sufficient evidence of anonymous or membership-free access. Its three courses are already included in all three live LearnDash tiers. Do not infer a new public-access policy from that category alone.

## Decisions required before implementation

1. Confirm that the 93 legacy/orphan lessons should remain excluded. The importer should produce their IDs/titles as a review artifact.
2. Decide the display order for the three courses currently tied at order zero.
3. Decide whether `Core`, `Master Exclusive`, and `Free Courses` become Collections, badges, filters, or a combination. Access must come from Access Groups, not category names.
4. Confirm whether the draft “Two Free US History Courses” product is intentionally retained.
5. Decide whether course summaries are written before launch or initially generated from a restrained editorial template. The source does not supply them.
6. Confirm ownership and long-term reachability of the S3 and `platform.tomwoods.com` media URLs.

## Implementation acceptance checks

- Exactly 39 Library Courses and 1,227 placed Content items are created.
- A second dry run reports no creates and no duplicates.
- Every course has the same ordered lesson count as the source table above.
- Exactly 14 Speaker profiles are created and every course has its expected ordered speakers.
- Every playable item has a normalized primary media source; the two download-only exceptions are explicitly accepted.
- Course images resolve and a representative sample of video, audio, PDF, S3, old-platform, HTTP, and relative URLs is tested.
- The Basic/Basic Plus/Master matrices equal 35/36/39 with zero missing and zero excess course grants.
- Test Membership receives no accidental access.
- Transcript coverage is reported as zero until real VTT files are supplied.
- LearnDash courses, lessons, groups, and MemberPress products are unchanged by the migration rehearsal.
