/** @jsx jsx */
import React, { useState, useEffect } from 'react'
import { jsx, css } from '@emotion/core'

export type ToastType = 'success' | 'info' | 'warning' | 'error'

interface Props {
  type: ToastType
  distance: number
  onClose(): void | Promise<void>
}

const icons = new Map<ToastType, string>([
  ['success', 'check'],
  ['info', 'info'],
  ['warning', 'exclamation-triangle'],
  ['error', 'times-circle'],
])

const wrapper = css`
  position: fixed;
  right: calc((100% - 350px) / 2);
  width: 350px;
  z-index: 1050;
  transition-property: top;
  transition-duration: 0.3s;
`
const shadow = css`
  box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
`

const Toast: React.FC<Props> = (props) => {
  const [show, setShow] = useState(false)

  useEffect(() => {
    const id1 = setTimeout(() => setShow(true), 100)
    const id2 = setTimeout(() => setShow(false), 3000)
    const id3 = setTimeout(props.onClose, 3100)

    return () => {
      clearTimeout(id1)
      clearTimeout(id2)
      clearTimeout(id3)
    }
  }, [props.onClose])

  const type = props.type === 'error' ? 'danger' : props.type

  const classes = [
    `alert alert-${type}`,
    'd-flex justify-content-between',
    'fade',
  ]
  if (show) {
    classes.push('show')
  }

  const role = type === 'success' || type === 'info' ? 'status' : 'alert'

  return (
    <div css={wrapper} style={{ top: `${props.distance}px` }}>
      <div className={classes.join(' ')} css={shadow} role={role}>
        <span className="mr-1 d-flex align-items-center">
          <i className={`icon fas fa-${icons.get(props.type)}`}></i>
        </span>
        <span>{props.children}</span>
        <button
          type="button"
          className="mr-2 ml-1 close"
          onClick={props.onClose}
        >
          &times;
        </button>
      </div>
    </div>
  )
}

export default Toast
