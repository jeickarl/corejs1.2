import { ApiProperty } from '@nestjs/swagger';

export class DashboardSearchItemDto {
  @ApiProperty({ enum: ['order', 'client', 'product'] })
  type!: 'order' | 'client' | 'product';

  @ApiProperty({ example: '/clients/1' })
  url!: string;

  @ApiProperty({ example: 'Cliente Juan' })
  title!: string;

  @ApiProperty({ example: '300... · correo...' })
  subtitle!: string;

  @ApiProperty({ example: 'fa-user' })
  icon!: string;
}

