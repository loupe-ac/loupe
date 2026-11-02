import type { ReactNode } from 'react';

// ActionForm
/**
 * A form that posts with a CSRF token and holds one button, such as an archive, a revoke or a skip.
 *
 * @startingPoint section="Core" subtitle="Every ActionForm variant" viewport="700x150"
 */
export interface ActionFormProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function ActionForm(props: ActionFormProps): JSX.Element;

// Avatar
/**
 * The first letter of a name, or an icon, in a disc or a rounded square. A dot shows a connection.
 *
 * @startingPoint section="Core" subtitle="Every Avatar variant and size" viewport="700x150"
 */
export interface AvatarProps {
    /** The visual weight and shape. */
    variant?:
        | 'accent'
        | 'tint'
        | 'ink'
        | 'danger'
        | 'chrome'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'xs' | 'sm' | 'md' | 'lg';
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Avatar(props: AvatarProps): JSX.Element;

// Badge
/**
 * A small label for the review status of a document.
 *
 * @startingPoint section="Core" subtitle="Every Badge variant" viewport="700x150"
 */
export interface BadgeProps {
    /** The visual weight and shape. */
    status?:
        | 'in-review'
        | 'draft'
        | 'approved'
        | 'changes-requested'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Badge(props: BadgeProps): JSX.Element;

// Button
/**
 * Any action or link that looks like a button. Sentence-case labels. Combine modifiers with a space, such as `ghost icon`.
 *
 * @startingPoint section="Core" subtitle="Every Button variant and size" viewport="700x330"
 */
export interface ButtonProps {
    /** The visual weight and shape. */
    variant?:
        | 'primary'
        | 'inverse'
        | 'outline'
        | 'success'
        | 'danger'
        | 'ghost'
        | 'danger-ghost'
        | 'on-card'
        | 'icon'
        | 'compact'
        | 'open'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'sm' | 'lg';
    disabled?: boolean;
    type?: 'button' | 'submit' | 'reset';
    /** Renders a link instead of a button. */
    href?: string;
    onClick?: () => void;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Button(props: ButtonProps): JSX.Element;

// CodeBlock
/**
 * A dark block of code or a command, with a header bar and a copy button.
 *
 * @startingPoint section="Core" subtitle="Every CodeBlock variant" viewport="700x150"
 */
export interface CodeBlockProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function CodeBlock(props: CodeBlockProps): JSX.Element;

// CopyButton
/**
 * A button that puts a text on the clipboard and shows that it did.
 *
 * @startingPoint section="Core" subtitle="Every CopyButton variant" viewport="700x150"
 */
export interface CopyButtonProps {
    /** The visual weight and shape. */
    variant?:
        | 'text'
        | 'icon'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function CopyButton(props: CopyButtonProps): JSX.Element;

// DataTable
/**
 * A list of one-line rows under a header. The caller sets the column grid with a modifier.
 *
 * @startingPoint section="Core" subtitle="Every DataTable variant" viewport="700x150"
 */
export interface DataTableProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function DataTable(props: DataTableProps): JSX.Element;

// Dialog
/**
 * A modal window over the page, opened by the modal controller.
 *
 * @startingPoint section="Core" subtitle="Every Dialog variant" viewport="700x420"
 */
export interface DialogProps {
    /** The visual weight and shape. */
    size?:
        | 'document'
        | 'search'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Dialog(props: DialogProps): JSX.Element;

// EmptyState
/**
 * The block that tells a user a list or page has no content yet.
 *
 * @startingPoint section="Core" subtitle="An EmptyState with an icon, a title, a sentence and a link" viewport="700x220"
 */
export interface EmptyStateProps {
    /** An icon, drawn as inline SVG. Optional. */
    icon?: ReactNode;
    /** A smaller block for an empty section inside a page. */
    compact?: boolean;
    /** A step of the icon scale, such as `xl`. `2xl` by default, and `xl` when compact. */
    iconSize?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    /** Colour classes of the icon. Optional. */
    iconClass?: string;
    /** The heading. Optional. */
    title?: string;
    /** The sentence under the heading. Optional. */
    body?: string;
    /** The text of the link. The link shows only when `linkHref` is set too. */
    linkLabel?: string;
    /** The target of the link. */
    linkHref?: string;
    className?: string;
    /** Goes after the body, for a second paragraph or an action. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function EmptyState(props: EmptyStateProps): JSX.Element;

// FieldErrors
/**
 * The list of errors of a form field.
 *
 * @startingPoint section="Core" subtitle="Every FieldErrors variant" viewport="700x150"
 */
export interface FieldErrorsProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FieldErrors(props: FieldErrorsProps): JSX.Element;

// FilterBar
/**
 * The search, selects, switch, clear link and count above a filtered list.
 *
 * @startingPoint section="Core" subtitle="Every FilterBar variant" viewport="700x150"
 */
export interface FilterBarProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FilterBar(props: FilterBarProps): JSX.Element;

// Flash
/**
 * A short message about the result of an action.
 *
 * @startingPoint section="Core" subtitle="Every Flash severity, with and without a dismiss button" viewport="700x190"
 */
export interface FlashProps {
    /** The colour of the dot. */
    severity?: 'success' | 'error' | 'warning' | 'info';
    /** The text of the dismiss button. Leave it out for a message with no button. */
    dismissLabel?: string;
    /** Draws the coloured dot. True by default. */
    dot?: boolean;
    className?: string;
    /** The message. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Flash(props: FlashProps): JSX.Element;

// FormActions
/**
 * The row of buttons that ends a form or a dialog.
 *
 * @startingPoint section="Core" subtitle="Every FormActions variant" viewport="700x150"
 */
export interface FormActionsProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FormActions(props: FormActionsProps): JSX.Element;

// FormField
/**
 * A form field with its label, control, hint and errors.
 *
 * @startingPoint section="Core" subtitle="Every FormField variant" viewport="700x150"
 */
export interface FormFieldProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FormField(props: FormFieldProps): JSX.Element;

// Hint
/**
 * A line of help under a form control.
 *
 * @startingPoint section="Core" subtitle="Every Hint variant" viewport="700x150"
 */
export interface HintProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Hint(props: HintProps): JSX.Element;

// Icon
/**
 * A size scale for the icon tag: xs is 3, sm is 3.5, md is 4, lg is 5, xl is 8 and 2xl is 12. xl and 2xl are for an empty-state icon. No component wraps it.
 *
 * @startingPoint section="Core" subtitle="Every Icon size" viewport="700x150"
 */
export interface IconProps {
    /** The size step. */
    variant?:
        | 'xs'
        | 'sm'
        | 'md'
        | 'lg'
        | 'xl'
        | '2xl'
        | (string & {});
    className?: string;
    /** The SVG shapes of a 24 by 24 Lucide icon. A check mark draws when you leave it out. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Icon(props: IconProps): JSX.Element;

// Input
/**
 * A one-line text field.
 *
 * @startingPoint section="Core" subtitle="An Input, plain and mono" viewport="700x150"
 */
export interface InputProps {
    /** Draws the value in the mono font. */
    mono?: boolean;
    className?: string;
    /** An input is a void element, so it takes no children. */
    children?: never;
    [attribute: string]: unknown;
}

export declare function Input(props: InputProps): JSX.Element;

// Label
/**
 * The name of a form control.
 *
 * @startingPoint section="Core" subtitle="Every Label variant" viewport="700x150"
 */
export interface LabelProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Label(props: LabelProps): JSX.Element;

// Menu
/**
 * A panel of actions or links that opens under a trigger, with items, headings and dividers.
 *
 * @startingPoint section="Core" subtitle="Every Menu variant and size" viewport="700x150"
 */
export interface MenuProps {
    /** The visual weight and shape. */
    variant?:
        | 'light'
        | 'chrome'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'sm' | 'md' | 'lg';
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Menu(props: MenuProps): JSX.Element;

// Pagination
/**
 * The previous, next and page-number links of a long list.
 *
 * @startingPoint section="Core" subtitle="A Pagination on the first, a middle and the last page" viewport="700x200"
 */
export interface PaginationProps {
    /** The current page, counting from 1. */
    page: number;
    /** The number of pages. The control draws nothing for 1 or less. */
    totalPages: number;
    /** The page numbers to draw, with `null` for a gap. All pages when left out. */
    pageList?: Array<number | null>;
    /** Returns the link of a page. A `#` link by default. */
    hrefFor?: (page: number) => string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Pagination(props: PaginationProps): JSX.Element | null;

// Panel
/**
 * A bordered tinted card that holds one block of a page.
 *
 * @startingPoint section="Core" subtitle="Every Panel variant" viewport="700x150"
 */
export interface PanelProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Panel(props: PanelProps): JSX.Element;

// RelativeTime
/**
 * A moment as a short age such as 2h ago, or as a date. The title holds the full timestamp.
 *
 * @startingPoint section="Core" subtitle="Every RelativeTime variant" viewport="700x150"
 */
export interface RelativeTimeProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function RelativeTime(props: RelativeTimeProps): JSX.Element;

// SectionHeading
/**
 * The title of a section, with an optional sentence and optional actions.
 *
 * @startingPoint section="Core" subtitle="Every SectionHeading variant" viewport="700x150"
 */
export interface SectionHeadingProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function SectionHeading(props: SectionHeadingProps): JSX.Element;

// Select
/**
 * A drop-down list of options.
 *
 * @startingPoint section="Core" subtitle="Every Select variant" viewport="700x150"
 */
export interface SelectProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Select(props: SelectProps): JSX.Element;

// StateMark
/**
 * An icon that names what a card needs now, with its reason in an interactive tooltip.
 *
 * @startingPoint section="Core" subtitle="A StateMark for each state" viewport="700x150"
 */
export interface StateMarkProps {
    /** The state. It picks the icon and the colour. */
    kind: 'stuck' | 'needs-you' | 'working' | 'waiting' | 'chip';
    /** The state name. It is the accessible name of the mark. */
    label: string;
    /** The tooltip id. Generated when left out. */
    id?: string;
    className?: string;
    /** The tooltip. It may hold a link. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function StateMark(props: StateMarkProps): JSX.Element;

// StatusBox
/**
 * The state of a card with its reason, its facts, what clears it, and the other states that apply.
 *
 * @startingPoint section="Core" subtitle="A StatusBox for a stuck card, with facts and other states" viewport="700x420"
 */
export interface StatusBoxProps {
    /** The state. It sets the colour and the icon of the chip. */
    kind: 'stuck' | 'needs-you' | 'working' | 'waiting' | 'chip';
    /** The heading of the box. */
    title: ReactNode;
    /** The chip text, the state and how long it has held. */
    chip: ReactNode;
    /** The sentence that says why the card has the state. */
    lead: ReactNode;
    /** Optional facts. Set `wide` for a sentence that takes the whole row. */
    fields?: { label: ReactNode; value: ReactNode; wide?: boolean }[];
    /** The other states that apply. */
    others?: { kind: string; label: ReactNode; lead: ReactNode; since?: ReactNode }[];
    /** The heading of the list of other states. */
    othersTitle?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function StatusBox(props: StatusBoxProps): JSX.Element;

// StatusChip
/**
 * A chip that names a state, with an optional reason in a tooltip.
 *
 * @startingPoint section="Core" subtitle="A StatusChip in each colour, with a reason" viewport="700x150"
 */
export interface StatusChipProps {
    /** The colour: pending, addressed, resolved, ok, failed, neutral. The default chip has none. */
    modifier?: 'pending' | 'addressed' | 'resolved' | 'ok' | 'failed' | 'neutral';
    /** The state name. The content is the label when this is left out. */
    label?: ReactNode;
    /** Draws the coloured dot. True by default. */
    dot?: boolean;
    /** The element, span by default. Use button for a chip that toggles. */
    as?: keyof JSX.IntrinsicElements;
    /** Why the state holds. Shown in a tooltip on hover and focus. */
    reason?: ReactNode;
    /** The tooltip id. Generated when left out. */
    id?: string;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function StatusChip(props: StatusChipProps): JSX.Element;

// Tabs
/**
 * A row of tabs that switches between views of one page, or a pill group that switches a view or a setting.
 *
 * @startingPoint section="Core" subtitle="Every Tabs variant" viewport="700x170"
 */
export interface TabsProps {
    /** The visual weight and shape. */
    variant?:
        | 'segmented'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tabs(props: TabsProps): JSX.Element;

// Tag
/**
 * A small coloured label for a category or a tone.
 *
 * @startingPoint section="Core" subtitle="Every Tag variant" viewport="700x150"
 */
export interface TagProps {
    /** The visual weight and shape. */
    tone?:
        | 'neutral'
        | 'lime'
        | 'purple'
        | 'green'
        | 'amber'
        | 'red'
        | 'teal'
        | 'sky'
        | 'blue'
        | 'indigo'
        | 'pink'
        | 'orange'
        | (string & {});
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tag(props: TagProps): JSX.Element;

// Textarea
/**
 * A text field of several lines.
 *
 * @startingPoint section="Core" subtitle="A Textarea with text" viewport="700x170"
 */
export interface TextareaProps {
    /** The text of the field. Pass it here, never as an element child. */
    defaultValue?: string;
    /** Plain text, taken as the default value. */
    children?: string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Textarea(props: TextareaProps): JSX.Element;

// Tooltip
/**
 * A short label that shows over an element on hover or focus. A touch screen hides it. The interactive variant stays open for a link.
 *
 * @startingPoint section="Core" subtitle="A Tooltip, plain and interactive" viewport="700x150"
 */
export interface TooltipProps {
    /** Keeps the tooltip open while the pointer or the focus is inside it, so it may hold a link. */
    interactive?: boolean;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tooltip(props: TooltipProps): JSX.Element;

// AppShell
/**
 * The page frame: a sidebar with the project switcher, a top bar with the breadcrumb, and the main column. It draws the markup of the signed-in pages: the project switcher, the nav, the account row and the top bar. The page goes in as the children.
 *
 * @startingPoint section="Layout" subtitle="The page frame with sidebar and top bar" viewport="900x520"
 */
export interface AppShellProps {
    /** The project of the sidebar, shown in the project switcher. On a page outside a project, the app shows the last project you visited. Pass null when there is none: the switcher, the project nav, Project settings and New work then stay hidden. */
    projectName?: string | null;
    /** True when the page belongs to the project. Pass false for a page outside a project, such as the account page: the sidebar keeps the project, and New work stays hidden. */
    projectPage?: boolean;
    /** The nav link that is current. */
    active?:
        | 'workshop'
        | 'inbox'
        | 'board'
        | 'documents'
        | 'artifacts'
        | 'agents'
        | 'workflow'
        | 'activity'
        | 'analytics'
        | 'outbox'
        | 'project_settings'
        | 'account_inboxes'
        | 'account'
        | 'admin'
        | 'about';
    /** The breadcrumb. A string is a crumb with no link target. The last one is the page you are on. */
    crumbs?: Array<string | { label: string; href: string }>;
    /** A short line after the breadcrumb, such as a count. */
    meta?: ReactNode;
    /** Page actions in the top bar, before the global actions. */
    actions?: ReactNode;
    /** The signed-in user, shown in the account row. */
    userName?: string;
    /** Numbers shown beside a nav link, keyed by the link name. Only inbox, documents, agents and activity take a count, as in the app. Agents takes text such as '1/3', the busy workers over the worker limit. Documents always shows its count, 0 included, and it counts the documents in review. Inbox and activity show it only above 0, and activity takes the number of open runs. */
    counts?: { [link: string]: number | string };
    /** The projects that the switcher lists, with the document count of each. The entry whose name is projectName gets the current marker. Defaults to projectName alone. */
    projects?: Array<{ name: string; documentCount?: number }>;
    /** The feature flags that are on, as the app reads them. Each is off by default, as on a fresh install. inbox shows Needs you and All inboxes, and artifacts shows Artifacts. */
    features?: { inbox?: boolean; artifacts?: boolean };
    /** True when the user is an admin. It shows the Admin link. */
    admin?: boolean;
    /** `full` drops the frame inset, for a page that reaches the window edge, such as the board. */
    shellWidth?: 'default' | 'full';
    /** The ground of the main column. `paper` is white, and `fill` is a column that clips its overflow. */
    mainSurface?: 'app' | 'paper' | 'fill';
    className?: string;
    /** The page. It fills the main column. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function AppShell(props: AppShellProps): JSX.Element;

// AuthShell
/**
 * The frame of every sign-in, sign-up and install page: a topbar with the brand mark, one centred card and a footer of legal links.
 *
 * @startingPoint section="Layout" subtitle="The sign-in page frame with a form" viewport="900x640"
 */
export interface AuthShellProps {
    /** Content at the right of the topbar, such as a link to the other sign-in page. */
    nav?: React.ReactNode;
    /** The footer row. Defaults to the app footer: the copyright and the legal links. Put custom links in a `lp-auth__footer-links` element, or pass `null` to hide the row. */
    footer?: React.ReactNode;
    /** Widens the card for a page that needs more than a form column. */
    wide?: boolean;
    className?: string;
    /** The content of the card: a heading, a form and a link. */
    children?: React.ReactNode;
    [attribute: string]: unknown;
}

export declare function AuthShell(props: AuthShellProps): JSX.Element;

// BrandMark
/**
 * The logo square and the brand name, as the sidebar, the sign-in pages and the marketing pages draw them.
 *
 * @startingPoint section="Layout" subtitle="Both sizes and the mark alone" viewport="700x150"
 */
export interface BrandMarkProps {
    /** The size step. md by default. */
    size?: 'sm' | 'md';
    /** Draws the logo square alone, named by the brand name. */
    markOnly?: boolean;
    className?: string;
    /** The mark draws the brand name itself, so it takes no children. */
    children?: never;
    [attribute: string]: unknown;
}

export declare function BrandMark(props: BrandMarkProps): JSX.Element;

// Breadcrumbs
/**
 * The trail at the top of a page: links to the parent pages and the current page last.
 *
 * @startingPoint section="Layout" subtitle="A trail of pages" viewport="700x120"
 */
export interface BreadcrumbsProps {
    /** The trail. A string is a crumb with no link. The last one is the page you are on. */
    items: Array<string | { label: string; href?: string }>;
    className?: string;
    /** Anything drawn after the list, such as a version pill. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Breadcrumbs(props: BreadcrumbsProps): JSX.Element;

// Drawer
/**
 * A panel that slides in from the right edge, with a header, a body that scrolls and a footer.
 *
 * @startingPoint section="Layout" subtitle="A header, a scrolling body and a footer" viewport="700x420"
 */
export interface DrawerProps {
    /** The header band, such as a title and a close button. Left out, no band is drawn. */
    header?: ReactNode;
    /** The body. It scrolls when it is taller than the drawer. */
    children?: ReactNode;
    /** The footer band, such as the main action. Left out, no band is drawn. */
    footer?: ReactNode;
    /** Pass `open` to show the dialog. */
    open?: boolean;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Drawer(props: DrawerProps): JSX.Element;

// ListRow
/**
 * One row of a list: an icon, a title and a meta line. The current row is highlighted.
 *
 * @startingPoint section="Layout" subtitle="A list row, open and not open" viewport="700x200"
 */
export interface ListRowProps {
    /** The row title. */
    title: ReactNode;
    /** The icon before the title. */
    icon?: ReactNode;
    /** The small line under the title, such as a source and an age. */
    meta?: ReactNode;
    /** True for the row that is open. It gets the highlight. */
    current?: boolean;
    /** The link target of the row. */
    href?: string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function ListRow(props: ListRowProps): JSX.Element;

// MarketingShell
/**
 * The frame of a public page for a signed-out visitor: the marketing header, the page body and the footer with the legal links.
 *
 * @startingPoint section="Layout" subtitle="Header, page body and footer" viewport="900x500"
 */
export interface MarketingShellProps {
    /** The page body, drawn between the header and the footer. */
    children?: React.ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function MarketingShell(props: MarketingShellProps): JSX.Element;

// NavItem
/**
 * One link of the sidebar: an icon, a label, an optional count and the current-page mark.
 *
 * @startingPoint section="Layout" subtitle="A sidebar link, current and not" viewport="700x200"
 */
export interface NavItemProps {
    /** The link text. */
    label: ReactNode;
    /** The icon, drawn before the label. Pass an svg that uses `currentColor`. */
    icon?: ReactNode;
    /** Marks the current page. */
    active?: boolean;
    /** A plain numeral at the end of the row. Left out when undefined. */
    count?: number;
    href?: string;
    className?: string;
    /** Anything else drawn after the label, such as an amber pill. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function NavItem(props: NavItemProps): JSX.Element;

// PageHeader
/**
 * The heading row of a page: a title, an optional description and the actions beside them.
 *
 * @startingPoint section="Layout" subtitle="The page heading, with and without actions" viewport="700x200"
 */
export interface PageHeaderProps {
    /** The page heading, drawn as the h1. */
    title: ReactNode;
    /** A sentence under the heading. Left out when empty. */
    description?: ReactNode;
    /** `workshop` gives the heading the workshop size. */
    variant?:
        | 'workshop'
        | (string & {});
    /** Extra class of the h1, such as `lp-workspace-title--doc`. */
    titleClass?: string;
    /** The buttons beside the heading. Drawn after the heading block. */
    actions?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function PageHeader(props: PageHeaderProps): JSX.Element;

// SettingsLayout
export interface SettingsLayoutItem {
    label: string;
    href: string;
    /** The icon before the label, such as a 16 px stroke icon. */
    icon?: ReactNode;
    /** True for the section that is open. It gets the highlight. */
    active?: boolean;
}

/**
 * A settings page: the page header, a section navigation beside the content, and the content column.
 *
 * @startingPoint section="Layout" subtitle="A settings page with its section navigation" viewport="900x520"
 */
export interface SettingsLayoutProps {
    /** The page heading, drawn as the h1. */
    title: ReactNode;
    /** A sentence under the heading. Left out when empty. */
    description?: ReactNode;
    /** The sections of the navigation, in order. */
    items?: SettingsLayoutItem[];
    /** The accessible name of the navigation. */
    navLabel?: string;
    /** Gives the content its own column width. */
    columns?: boolean;
    className?: string;
    /** The content of the open section: one or more `Panel` sections. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function SettingsLayout(props: SettingsLayoutProps): JSX.Element;

// SplitView
/**
 * A master-detail page: a held header over a list pane beside a detail pane, each scrolling on its own.
 *
 * @startingPoint section="Layout" subtitle="A list pane beside a detail pane" viewport="700x320"
 */
export interface SplitViewProps {
    /** The held header, such as a PageHeader. Drawn above the panes and never scrolls. */
    header?: ReactNode;
    /** The list pane, on the left. It scrolls on its own. Fill it with ListRow parts. */
    list?: ReactNode;
    /** The detail pane, on the right. It scrolls on its own. */
    detail?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function SplitView(props: SplitViewProps): JSX.Element;

// Workspace
/**
 * The page container: the frame that holds the content of a page, with a named width.
 *
 * @startingPoint section="Layout" subtitle="The frame of a page" viewport="700x200"
 */
export interface WorkspaceProps {
    /** The width of the frame. `default` is the standard page, `narrow` suits a form, and `full` drops the cap for a viewport-sized page. */
    width?:
        | 'default'
        | 'narrow'
        | 'full'
        | (string & {});
    /** The content of the page. */
    children?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Workspace(props: WorkspaceProps): JSX.Element;
