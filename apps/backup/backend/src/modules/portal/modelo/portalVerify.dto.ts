import { ApiProperty } from '@nestjs/swagger';

export class PortalVerifyDto {
  @ApiProperty({ enum: ['order', 'id'] })
  mode!: 'order' | 'id';

  @ApiProperty({ example: 'WO-1 o código' })
  query!: string;
}

