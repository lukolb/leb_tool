# LaTeX support files

Common LaTeX support files (for example AcroTeX/eforms files such as
`eforms.sty`, `insdljs.sty`, `taborder.sty`, `epdftex.def`, `pdfdochex.def`
or `dljslib.sty`) can be placed here. During builds with imported LaTeX
template packages, these files are added server-side when the package does not
provide a file with the same relative path.

For backward compatibility, the build helper also checks the legacy `latex/`
root for the same support filenames.
